# 11 Development Setup

## Stack

| Component | Version | Notes |
|---|---|---|
| PHP | 8.4 (fpm-alpine) | `bcmath` is required — money arithmetic must not use binary floats (docs/10 rule 4) |
| Laravel | 12.x | |
| MySQL | 8.4 | `ENUM`, `JSON` and `DECIMAL` semantics are relied upon; see `database/schema.sql` |
| Redis | 7 | cache + queue driver |
| nginx | 1.27 | serves `public/`, denies `/storage` |

Everything runs in Docker. No local PHP, Composer or MySQL install is needed —
the host's XAMPP PHP 8.0 is too old for Laravel 12 and is not used.

## First run

```bash
cp .env.example .env
```

```bash
docker compose up -d --build
```

```bash
docker compose exec app php artisan key:generate
```

```bash
docker compose exec app php artisan migrate
```

The app is then at http://localhost:8080 and the health endpoint at
http://localhost:8080/up.

## Services

| Service | Purpose |
|---|---|
| `app` | PHP-FPM, and the container you run `artisan`/`composer` in |
| `web` | nginx on `APP_PORT` (default 8080) |
| `mysql` | dev database `ev_charging` + test database `ev_charging_test`, host port 33061 |
| `redis` | cache and queue backend |
| `queue` | `queue:work` — processes OCR/AI jobs (docs/03 → async OCR jobs) |
| `scheduler` | `schedule:work` — reports, notifications, backups |

The test database is created automatically on the first MySQL start by
`docker/mysql/init/01-create-test-database.sql`.

## Everyday commands

Run any artisan command inside the `app` container:

```bash
docker compose exec app php artisan migrate:status
```

Quality gates — these are exactly what CI runs:

```bash
docker compose exec app composer check
```

Individually:

```bash
docker compose exec app vendor/bin/pint --test
```

```bash
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G
```

```bash
docker compose exec app php artisan test
```

## Why tests use MySQL, not SQLite

The Laravel skeleton defaults `phpunit.xml` to SQLite in-memory. That default has
been removed. SQLite stores `DECIMAL` as a float and does not enforce `ENUM`
constraints, so a green SQLite suite would not prove that money arithmetic and
status transitions behave correctly in production. `RefreshDatabase` runs
against `ev_charging_test`, which is a separate database from `ev_charging` so
dev data is never truncated by a test run.

## Code standards

- **Pint** with the `laravel` preset plus `declare_strict_types`,
  `strict_comparison` and `strict_param`. Strict comparison is not cosmetic
  here: `==` on monetary strings from `DECIMAL` columns performs numeric
  coercion and would silently equate `"10.00"` with `"10.000"`.
- **Larastan** at level 6, raising toward 8 as the domain layer stabilises (M6).
- Pest for tests. Feature tests boot the app and hit MySQL; unit tests stay
  framework-light so cost and tariff rules can be exercised in isolation.

## Configuration boundaries

No business data is hard-coded (docs/10 rule 9). Tariff rates, VAT and FT live
in the database as versioned records (`tariff_versions`), never in config or
code. Config files hold only operational settings:

| File | Holds |
|---|---|
| `config/receipts.php` | allowed MIME types, magic-byte signatures, size cap, private disk name |
| `config/ocr.php` | provider driver, timeout, review threshold, retry/backoff |
| `config/app.php` | `timezone` (UTC, authoritative) and `display_timezone` (Asia/Bangkok, presentation only) |

### The `none` driver

`OCR_DRIVER` and `AI_DRIVER` default to `none`, a no-op adapter, so a fresh
checkout and CI never make external calls. Do **not** use the literal `null` as
a driver value: Laravel's `env()` casts the string `"null"` to PHP `null`, which
blanks the value instead of falling back to the config default.

## Private receipt files

Receipts are written to the `receipts` disk, rooted at
`storage/app/private/receipts`, which is outside `public/`. That disk has
`serve => false` and deliberately no `url` key, and nginx returns 404 for any
`/storage` request. Receipt files are streamed only through a controller that
runs the receipt policy (docs/03, AT-007). `tests/Unit/ReceiptStorageConfigTest.php`
fails CI if any of those properties regress.

## Environment variables

Never commit `.env`. `.env.example` is the documented contract — add every new
key there with a comment, and never put a real credential in it.
