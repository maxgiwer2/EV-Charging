# 14 Deployment and Operations

Production deployment, hardening and recovery (docs/09 M6, docs/03).

---

## Before you deploy

```bash
php artisan app:check-production
```

Ten checks covering the settings whose absence is invisible in development and
expensive afterwards. The application **refuses to boot** in production if the
critical ones fail — `ProductionServiceProvider` throws on start.

That is deliberate. A system that comes up holding financial records with debug
mode on is worse than one that refuses to start and says why: the first fails
quietly for as long as nobody looks.

| Must hold in production | Why |
|---|---|
| `APP_DEBUG=false` | Debug pages disclose stack traces, queries and env values |
| `APP_KEY` set | Sessions and encrypted values cannot be secured without it |
| `APP_URL=https://…` | Generated links would otherwise downgrade to http |
| `SESSION_SECURE_COOKIE=true` | Else the session cookie travels in clear |
| `SESSION_ENCRYPT=true` | Session contents readable in the store otherwise |
| Receipts disk private, no `url` | A public disk exposes private financial documents (AT-007) |
| `QUEUE_CONNECTION` not `sync` | OCR would run inside the request and time out |
| `DB_CONNECTION=mysql` | `DECIMAL`/`ENUM` semantics are relied upon |

---

## Environment

Beyond `.env.example`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ev.example.com
APP_VERSION=$(git rev-parse --short HEAD)    # reported by /health/ready

SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true

# Only the proxies actually in front of the app. `*` lets a client spoof the IP
# written to the audit trail (FR-015), so use it only where the network
# guarantees a single proxy.
TRUSTED_PROXIES=10.0.0.0/8

LOG_LEVEL=info        # `debug` logs query bindings
```

Never commit a populated `.env`. `TYPHOON_API_KEY` and `OLLAMA_BASE_URL` are
credentials/infrastructure and belong in the deployment secret store.

---

## Topology

```
        HTTPS
          │
      ┌───▼────┐      ┌──────────┐
      │ nginx  │─────▶│ php-fpm  │──┬──▶ MySQL 8
      └────────┘      └──────────┘  ├──▶ Redis (cache + queue)
                                    └──▶ private disk (receipts)
      ┌──────────────┐
      │ queue worker │──▶ OCR / AI adapters
      ├──────────────┤
      │ scheduler    │──▶ backup, insights, token pruning
      └──────────────┘
```

The queue worker is **not optional**: receipt OCR is dispatched to it, so
without a worker every upload sits in `OCR_PENDING` forever.

### Building for a Linux host

The container user must own the bind mount, and that uid is not always 1000:

```bash
APP_UID=$(id -u) APP_GID=$(id -g) docker compose build app
```

---

## Release steps

```bash
php artisan down --render="errors::503"
```

```bash
composer install --no-dev --optimize-autoloader && npm ci && npm run build
```

```bash
php artisan migrate --force
```

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

```bash
php artisan up
```

Restart the queue workers afterwards — a running worker holds the old code in
memory:

```bash
php artisan queue:restart
```

### Rollback

Migrations are written to be reversible and CI proves it on every push
(`migrate:rollback --step=100`). To roll back a release:

```bash
php artisan down && php artisan migrate:rollback --step=1
```

Then deploy the previous image and `php artisan up`. **Check the migration
first**: a rollback that drops a column loses the data in it. If the release
added a column that now holds financial data, restore from backup instead.

---

## Health checks

| Endpoint | Use | Behaviour |
|---|---|---|
| `/health/live` | liveness probe | 200 if PHP is running; no dependencies |
| `/health/ready` | readiness / load balancer | 503 if MySQL, Redis, storage or the jobs table is unreachable |
| `/up` | Laravel default | kept for compatibility |

Point the load balancer at **`/health/ready`**. Laravel's `/up` only proves PHP
booted, so an instance whose database is down answers it happily and keeps
receiving traffic it cannot serve.

Failures are reported per dependency by name but never with the underlying
message — a connection error names hosts, ports and sometimes credentials, and
these endpoints are unauthenticated.

---

## Backup and restore

### Taking a backup

```bash
php artisan backup:run
```

Backs up **both** the database and the receipt files. Either alone is half a
recovery: the database holds the financial records and the private disk holds
the documents that evidence them, and auditable records need both.

Runs nightly at 02:30 via the scheduler, keeping 7 of each. The dump uses a
single transaction, so it is consistent without locking and does not block
charging entries being recorded. Retention is capped rather than unbounded
because these files hold complete financial records — every extra copy is
another place they can leak from.

`backup:run` verifies its own output before reporting success, so a broken
backup fails loudly at the time rather than at restore.

### Verifying one

```bash
php artisan backup:verify db-20260818-023000.sql.gz
```

A separate step on purpose. **An untested backup is a hope, not a recovery
plan** — and the failure people actually hit is discovering at restore time that
a dump was truncated by a full disk. This checks the gzip CRC *and* that the
tables that matter are present, because a valid gzip stream can still hold a
dump that failed partway through.

Safe against production backups; it restores nothing.

### Restoring

```bash
php artisan down
```

```bash
gzip -dc db-20260818-023000.sql.gz > /tmp/restore.sql
```

```bash
docker compose exec -T mysql sh -c 'cat > /tmp/r.sql' < /tmp/restore.sql
```

```bash
docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < /tmp/r.sql && rm /tmp/r.sql'
```

```bash
tar -xzf receipts-20260818-023000.tar.gz -C storage/app/private/receipts
```

```bash
php artisan migrate --force && php artisan up
```

Restore the receipts archive from the **same** backup run as the database dump.
A mismatched pair gives sessions whose receipts are missing, or receipts
referencing sessions that no longer exist.

The client runs **inside the database container**: the app image deliberately
carries no MySQL client (see below), and the mysql image ships the matching one.

**Rehearse this against a staging database.** A restore procedure first executed
during an incident is a procedure with unknown steps. This one has been
rehearsed — restoring a dump into a scratch database reproduced 6 sessions,
3 users and a confirmed total of 1385.84, matching the live dashboard exactly.

### Why the dump is produced in PHP

`backup:run` dumps through PDO rather than shelling out to `mysqldump`. The
first implementation shelled out and failed twice over, both worth knowing:

1. Alpine ships **MariaDB's** client, which cannot authenticate against MySQL 8
   (no `caching_sha2_password` plugin), so every dump came out empty.
2. The command was `mysqldump | gzip > file`, and a shell pipeline reports the
   exit code of its **last** command. `gzip` succeeded, so the backup reported
   success while writing a 20-byte file.

`backup:run` now verifies its own output before reporting success, and the app
image carries no MySQL client at all — leaving a broken one installed would
suggest it works.

---

## Observability

Every request carries an `X-Request-Id`, generated or taken from an inbound
header, attached to the log context and returned in the response. One id ties
the whole request together, so a user reporting a problem can quote it. An
inbound value is validated against a strict pattern first — it reaches log files,
and unvalidated input in a log line invites forged entries.

Log at `info` in production; `debug` includes query bindings, which for this
application means financial values in log files.

Never logged (docs/10 rule 13): passwords, tokens, receipt contents, receipt
storage paths, or assistant questions. `AuditLogService` redacts by key, so
anything matching `password`, `token`, `secret`, `api_key`, `file_path` or
`raw_payload` is stored as `[redacted]`.

### What to watch

| Signal | Meaning |
|---|---|
| `/health/ready` returning 503 | A dependency is down; instance should leave the pool |
| Receipts stuck in `OCR_PENDING` | Queue worker is not running |
| `Assistant narration rejected` in logs | The model is inventing figures; revisit prompt or model |
| Failed jobs growing | OCR provider erroring; check the adapter's logged status |
| `backup:verify` failing | Backups are not recoverable — treat as urgent |

---

## Security posture

Applied by `SecurityHeaders` middleware on every response, with nginx keeping a
copy as defence in depth:

`X-Frame-Options: DENY` · `X-Content-Type-Options: nosniff` ·
`Referrer-Policy: strict-origin-when-cross-origin` · `Permissions-Policy` denying
geolocation/microphone/payment/usb · `Strict-Transport-Security` (HTTPS only) ·
`Content-Security-Policy` on HTML.

CSP allows `'unsafe-inline'` for **styles** only — Blade templates use inline
`style` attributes for the chart and progress bars. `script-src` stays strict,
which is the half that blocks injected code.

Authenticated HTML and JSON get `Cache-Control: private, no-store`. Fingerprinted
assets stay cacheable.

Rate limits: 60/min general API, 10/min assistant (a local model takes seconds
per call), 5/min login keyed on email+IP so guessing cannot be spread across the
general budget.

### Known limitation

`Strict-Transport-Security` is only sent over HTTPS, so TLS termination must
happen at a proxy that forwards `X-Forwarded-Proto` **and** be listed in
`TRUSTED_PROXIES`. Without both, Laravel sees plain HTTP, omits HSTS and
generates `http://` URLs.
