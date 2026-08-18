# 12 Improvement Backlog

Known gaps after M0–M2, with the reasoning behind each priority. Written at the
start of M3 so nothing carried forward is implicit.

Severity is about **risk to correctness or security**, not effort.

---

## P0 — Correctness holes — RESOLVED IN M3

### 1. A manually entered session can never be confirmed

`SessionStatus::CONFIRMED` is written in exactly one place: `ReceiptService`
when a human verifies a receipt. Sessions created through
`POST /charging-sessions` are `DRAFT` and have no route out of it.

Consequences:

- `docs/04` **Manual Entry** ends "save → dashboard update", which cannot
  happen — every dashboard total filters on `CONFIRMED` (AT-009).
- `docs/04` **Quick Entry** is likewise inert.
- A user who charges at home (no receipt to scan) can record nothing that counts.

This is the single most serious gap: the system currently only works for the
receipt path. **Resolved in M3:** `ChargingSessionService` plus
`POST /charging-sessions/{id}/confirm|cancel|reopen`.

### 2. No cost engine, so no derived metrics exist

`docs/06` defines `cost_per_kwh`, `cost_per_km`, `kwh_per_100km`, `km_per_kwh`
and `cost_per_100km`, and requires that **none of them are calculated when the
denominator is zero or null**. Nothing in the codebase computes any of them yet
(grep for `cost_per_kwh` returns zero hits outside the docs).

The zero-denominator rule is the trap: a `0` returned for `cost_per_km` on a
session with no distance reads as "free driving" and silently corrupts every
average built on top of it. The distinction between *null* (unknowable) and
*zero* (a real measured value) has to hold end to end.

**Resolved in M3:** `Money`, `SessionMetrics` and `CostCalculationService`,
with null propagation covered by tests.

### 3. FR-009 energy-source precedence is modelled but never enforced

`EnergySource::outranks()` exists and is unit-tested, but no code path consults
it. `ReceiptService` overwrites `energy_kwh` unconditionally on verification.
Today that is harmless — a receipt is the highest precedence anyway — but the
moment a charger reading or SOC estimate can update a session, an estimate could
silently replace a billed figure.

**Resolved in M3:** every energy write goes through
`CostCalculationService::resolveEnergy()`.

---

## P1 — Functional gaps, M3–M4

### 4. No real OCR provider

Only the `none` adapter exists, so every receipt reaches review with no
extracted values and must be keyed in by hand. The abstraction is complete
(`OcrProviderInterface`, `OcrProviderManager`), so adding a real provider is
self-contained and touches no domain code.

Deferred deliberately: a provider needs credentials and a billing account, and
the review flow is correct without one. **Owner decision required** on provider
(Google Document AI, AWS Textract, Typhoon OCR for Thai receipts).

### 5. `bySimilarTransaction` rarely fires

The heuristic compares against charging sessions linked to existing receipts,
but at upload time a new receipt has no session yet, so in practice only the
hash and receipt-number signals do work. It becomes useful once a library of
verified receipts exists. Not wrong, just currently near-inert. Revisit with
real data rather than tuning it blind.

### 6. No web UI for upload, vehicles or sessions

The review UI exists; everything else is API-only. `docs/03` calls for
mobile-first quick entry (`docs/04` Quick Entry). **M3 added the dashboard; quick-entry and
upload UI still outstanding.**

### 7. Budgets and notifications are data-only

`budgets` and `notifications` tables, models and factories exist. FR-013
thresholds (50/80/100%) and FR-014 alerts have no service behind them.
Notifications *are* raised for duplicates and review, so the plumbing works.
**Still outstanding.** The cost engine that produces the spend figures now
exists, so budget evaluation can build directly on `AnalyticsService` — M4.

---

## P2 — Hardening, M6

| # | Gap | Note |
|---|---|---|
| 8 | Larastan at level 6 | Raise toward 8 once the domain layer settles |
| 9 | `tests/` excluded from static analysis | Pest rebinds `$this` in closures; revisit if a maintained extension appears |
| 10 | Blade templates unanalysed | Consider `blade-formatter` / a Blade-aware linter |
| 11 | `SESSION_SECURE_COOKIE=false` | Must be `true` in production; needs the deployment guide |
| 12 | No backup/restore procedure | `docs/03` reliability requirement |
| 13 | Docker uid hardcoded to 1000 | Breaks bind-mount writes on Linux hosts with a different uid |
| 14 | `notifications` table name | Collides with Laravel's database notification channel if that is ever adopted |
| 15 | `duplicate_matches` is a point-in-time snapshot | Deliberate — shows what was flagged at the time — but can look stale next to newer uploads |

---

## Sequencing rationale

P0 items are all in the same area of the system: the path from "a charge
happened" to "a number on the dashboard". Fixing them separately would mean
touching the cost engine three times, so M3 addresses them together:

1. Money handling that cannot lose precision (`decimal`-safe throughout).
2. A cost engine that owns energy precedence and total calculation.
3. Confirm/cancel actions, giving manual entry a route to `CONFIRMED`.
4. Metrics with strict null propagation.
5. Analytics, reports and exports on top.

Item 4 (OCR provider) is intentionally *not* in M3: it is blocked on an external
account, and the pipeline is already correct and tested without it.
