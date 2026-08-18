# 07 API Specification

Base: `/api/v1`

## Auth
POST `/auth/login`
POST `/auth/logout`
GET `/auth/me`

## Vehicles
GET/POST `/vehicles`
GET/PUT/DELETE `/vehicles/{id}`

## Stations
GET/POST `/stations`
GET/PUT/DELETE `/stations/{id}`

## Charging
GET/POST `/charging-sessions`
GET/PUT/DELETE `/charging-sessions/{id}`

## Receipts
POST `/receipts`
GET `/receipts/{id}`
POST `/receipts/{id}/ocr`
POST `/receipts/{id}/verify`

## Dashboard
GET `/dashboard/summary`
GET `/dashboard/trends`
GET `/dashboard/breakdowns`

## Reports
GET `/reports/charging`
GET `/reports/vehicles`
GET `/reports/stations`
GET `/reports/networks`

## Tariffs
GET/POST `/tariffs`
GET/PUT/DELETE `/tariffs/{id}`

All endpoints require authorization as appropriate, validate input, paginate collections, return consistent JSON envelopes, and never expose private file paths.

---

## Implementation Notes (M1)

### Response envelope

Success returns `data`, with `meta` added for paginated collections:

```json
{ "data": { "id": 1, "make": "BYD" } }
```

```json
{ "data": [ ... ], "meta": { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 } }
```

Failure always returns `error` with a stable `code` (docs/10 rule 14).
`details` carries field-level validation messages:

```json
{ "error": { "code": "VALIDATION_FAILED", "message": "The given data was invalid.",
             "details": { "soc_after": ["State of charge after charging must not be lower than before."] } } }
```

### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `UNAUTHENTICATED` | 401 | No or invalid token |
| `FORBIDDEN` | 403 | Role does not permit the action |
| `NOT_FOUND` | 404 | No such record, **or** it belongs to another user |
| `VALIDATION_FAILED` | 422 | Input rejected |
| `CONFLICT` | 409 | State conflict |
| `INVALID_STATE_TRANSITION` | 409 | Illegal status change (M2) |
| `DUPLICATE_RECEIPT` | 409 | Probable duplicate (M2) |
| `UNSUPPORTED_FILE_TYPE` | 422 | Upload rejected (M2) |
| `TARIFF_OVERLAP` | 409 | Overlapping effective period (M4) |
| `RATE_LIMITED` | 429 | Throttled |
| `SERVER_ERROR` | 500 | Unexpected failure |

**404 vs 403 is deliberate.** Requesting another user's record returns `NOT_FOUND`,
not `FORBIDDEN` — a 403 would confirm that the id exists and allow enumeration of
other users' records. `FORBIDDEN` is returned only when the caller may legitimately
see the record but their role forbids the action (a viewer attempting a write).

### Authentication

`POST /auth/login` returns a Sanctum token; send it as `Authorization: Bearer <token>`.
`POST /auth/logout` revokes only the calling token, so other devices stay signed in.
Login is rate limited to 5 attempts per minute per email+IP; the rest of the API to
60 per minute per user.

### Endpoints implemented in M1

| Method | Path | Authorization |
|---|---|---|
| POST | `/auth/login` | public |
| POST | `/auth/logout` | token |
| GET | `/auth/me` | token |
| GET/POST | `/vehicles` | own records; write requires non-viewer |
| GET/PUT/DELETE | `/vehicles/{id}` | own records (admin: all) |
| GET/POST | `/charging-sessions` | own records; write requires non-viewer |
| GET/PUT/DELETE | `/charging-sessions/{id}` | own records (admin: all) |
| GET | `/networks`, `/stations`, `/connectors` | any authenticated user |
| POST/PUT/DELETE | `/networks/{id}`, `/stations/{id}`, `/connectors/{id}` | admin only |

Collection endpoints accept `per_page` (max 100). `/charging-sessions` additionally
accepts `vehicle_id`, `station_id`, `charging_type`, `status`, `from`, `to`.
The `to` bound is exclusive, so a session on a period boundary is never counted twice.

### Not yet implemented

`/receipts` (M2), `/dashboard` and `/reports` (M3), `/tariffs` (M4).

### Field conventions

- Timestamps are ISO-8601 UTC. Clients render Asia/Bangkok (docs/10 rule 7).
- Money and other decimals are **strings**, not JSON numbers, so `DECIMAL`
  precision survives the round trip. A JSON number would be parsed as a float
  by most clients and defeat docs/10 rule 4.
- Sessions are created as `DRAFT`. Money fields are never accepted from the
  client; totals come from the cost engine (M3).

---

## Receipts (M2)

| Method | Path | Notes |
|---|---|---|
| GET | `/receipts` | own receipts (admin: all); filters `awaiting_review`, `status` |
| POST | `/receipts` | multipart `file`, optional `charging_session_id` |
| GET | `/receipts/{id}` | includes `ocr_results` with per-field confidence |
| GET | `/receipts/{id}/download` | streams the file; **only** way to read it |
| POST | `/receipts/{id}/ocr` | re-queue OCR; 202 |
| POST | `/receipts/{id}/verify` | human confirmation — the only path to `VERIFIED` |
| POST | `/receipts/{id}/reject` | dismiss, optional `reason` |

### Upload validation

Both the detected MIME type and the file's leading magic bytes must match the
allowlist in `config/receipts.php`. A client-supplied `Content-Type` and the
filename extension are never trusted, so a script renamed to `.jpg` is rejected.
The stored filename is a generated ULID; the client's filename is kept only for
display. Size cap comes from `RECEIPT_MAX_SIZE_KB`.

### Review lifecycle

```
OCR_PENDING -> OCR_PROCESSING -> OCR_REVIEW -> VERIFIED | REJECTED
```

`VERIFIED` and `REJECTED` are terminal. An illegal transition returns
`INVALID_STATE_TRANSITION` (409).

**OCR never auto-verifies** (FR-005, AT-004). Every run lands in `OCR_REVIEW`
regardless of confidence, including a failed run — a human can still key the
values in from the stored image. Confidence only decides which fields the review
UI highlights.

### Verification payload

`POST /receipts/{id}/verify` takes the values a human approved, which may differ
from what OCR read:

```json
{ "vehicle_id": 1, "charging_type": "PUBLIC", "transaction_date": "2026-08-18T10:30:00Z",
  "energy_kwh": 42.5, "unit_price": 7.5, "subtotal": 318.75, "vat": 22.31, "total": 341.06 }
```

The breakdown must add up to `total` within 0.01 — real receipts round each line
independently, so an exact match would reject legitimate paperwork, but a larger
gap means a mistyped figure that would otherwise become the charged amount.

On success the receipt is linked to a `CONFIRMED` charging session with
`energy_source: RECEIPT` (the highest precedence in FR-009), and the breakdown is
frozen into `charging_cost_lines` (AT-006).

### Originals are never overwritten

`receipt_ocr_results` is append-only: each run inserts a row, and a reviewer's
corrections go to `receipts.verified_data` instead. A disputed figure can always
be traced both to what the provider read and to what a person approved
(docs/05, README rule 1).

### Duplicates are flagged, not blocked

A probable duplicate still uploads successfully (201) with `duplicate_matches`
populated and a notification raised. AT-005 requires flagging, and re-uploading
the same image to correct a mis-keyed session is legitimate. Signals: identical
file hash (1.0), shared receipt number (0.9), same station/amount/energy within
90 minutes (0.7). Comparison never crosses users.

### OCR provider

Domain code depends on `OcrProviderInterface` only. `config('ocr.driver')`
selects the adapter; register new ones in `OcrProviderManager`. The default
`none` driver extracts nothing and says so, rather than inventing values.

---

## Analytics, Reports and Exports (M3)

| Method | Path | Notes |
|---|---|---|
| POST | `/charging-sessions/{id}/confirm` | DRAFT → CONFIRMED; the route out of draft for manual entry |
| POST | `/charging-sessions/{id}/cancel` | stops counting; keeps the row and audit trail |
| POST | `/charging-sessions/{id}/reopen` | CANCELLED → DRAFT for correction |
| GET | `/dashboard/summary` | KPIs + previous-period comparison |
| GET | `/dashboard/trends` | `granularity=day\|month\|year` |
| GET | `/dashboard/breakdowns` | `dimension=charging_type\|charging_mode\|station\|network\|vehicle` |
| GET | `/reports/charging` | row-level, capped at 1000 |
| GET | `/reports/vehicles`, `/stations`, `/networks` | grouped spend |
| GET | `/reports/export` | `format=csv\|xlsx\|pdf` |

### Session lifecycle

```
DRAFT ⇄ CANCELLED
  ↓
CONFIRMED → CANCELLED
```

Only `CONFIRMED` sessions count toward any total (AT-009). Sessions are created
as `DRAFT`; confirming is a deliberate act separate from editing, because it is
the point where an entry becomes financial fact. Confirming twice is idempotent.
Cancelling never deletes — the row and its audit trail survive (docs/10 rule 15).

### Money handling

Money is accepted on a session (someone recording a home charge knows what they
paid) but the columns are **not fillable**: values are validated, then
recomposed by `CostCalculationService` using bcmath so the parts always
reconcile with the total. Supply `unit_price` without `subtotal` and the
subtotal is derived from energy × rate, rounded once at the end.

### Energy precedence (FR-009)

`RECEIPT` and `CHARGER` outrank `MANUAL`, which outranks `SOC_ESTIMATE`. A
lower-precedence value is **silently ignored** rather than overwriting a better
one; equal precedence is allowed through so a corrected reading can replace an
earlier one. When no energy is supplied and the vehicle has a battery capacity,
a SOC-derived estimate is used — and when the capacity is unknown, energy stays
null rather than being guessed.

### Uncomputable metrics are null, never zero

docs/06 forbids calculating a metric whose denominator is zero or null. Those
fields return `null` in JSON, an empty cell in CSV/XLSX, and an em dash in the
UI. A `0` would read as a measured value — "free driving" for `cost_per_km` —
and silently corrupt every average built on it.

### Exports (AT-008)

All formats share `AnalyticsFilter` with the dashboard, so an export contains
exactly the records the same filter selects. CSV and XLSX stream row by row and
are unbounded; PDF is capped at 1000 rows and says so in the document. CSV
carries a UTF-8 BOM so Excel on Windows renders Thai station names correctly.
Every export writes an `EXPORT` audit entry — it moves financial data out of the
system.

---

## Tariffs (M4)

| Method | Path | Authorization |
|---|---|---|
| GET | `/tariffs`, `/tariffs/{id}` | any authenticated user |
| POST/PUT/DELETE | `/tariffs`, `/tariffs/{id}` | admin |
| POST | `/tariffs/{id}/versions` | admin — publish a priced version |
| PUT | `/tariffs/{id}/versions/{version}` | admin — amend an **unused** version |

A tariff is the stable identity of a pricing scheme; every rate lives in a
version with an effective period. Reads are open because a user is entitled to
see the rate they were charged.

### Versions are immutable once used (AT-006)

Amending a version that has priced a charging session returns `CONFLICT` (409).
At that point the version is evidence, not configuration — editing its rates
would silently rewrite historical totals. A rate change publishes a new version.
`is_locked` on the version resource tells a client which state it is in. The
check counts soft-deleted sessions too: a deleted financial record still has to
stay explainable.

### Overlap validation

Publishing a version whose period overlaps an existing one of the **same time
band** returns `TARIFF_OVERLAP` (409). MySQL cannot express a non-overlap
constraint over a date range, so `TariffService` enforces it under a row lock —
two admins publishing simultaneously would otherwise both pass the check.

Peak and off-peak versions may share dates: they cover the same days but
different hours. Publishing an open-ended version automatically closes the
previous open-ended one of that band at the new start date, so the timeline has
no gap and no instant covered twice.

### Resolution and pricing

When a session is saved with **no money supplied**, the tariff in force is
resolved and applied, and `tariff_version_id` is stored — that snapshot is what
makes the total reproducible years later. Resolution narrows by scope
(station-specific beats network-wide), then power band (upper bound exclusive,
so `0–50` and `50–` do not both match at 50 kW), then time band.

A supplied amount always wins: what a driver was actually billed is the fact,
and a tariff is only an expectation of it. When no tariff matches, the session
is left unpriced rather than being given someone else's rate.

`vat_rate` is nullable and means "this tariff does not state a VAT rate", which
is **not** the same as 0% — no tax is added that was never charged.

### Time-of-use windows

Peak hours are configuration (`config/tariffs.php`), not constants in code
(docs/10 rule 9), and are expressed in the display timezone because a TOU window
is defined in local time.

---

## OCR provider: Typhoon (M4)

`OCR_DRIVER=typhoon` plus `TYPHOON_API_KEY` enables real extraction via
opentyphoon.ai — a Thai-first document VLM, chosen because Thai charging
receipts mix Thai and English labels that general OCR engines mangle. The API is
OpenAI-compatible (`POST {base_url}/chat/completions`, Bearer token).

The model returns layout-aware **Markdown**, not structured fields, so
`TyphoonOcrProvider` obtains the text and `ReceiptParserService` recovers the
docs/05 fields deterministically. Asking the model for JSON directly would be
shorter but would let it produce a plausible total for an unreadable line, which
docs/05 forbids.

Confidence reflects *how* a value was found — an explicitly labelled amount
scores higher than one recovered by arithmetic — and never reaches 1.0, because
a value read out of OCR text is always an inference. Receipts are sent as base64
data URIs, never as fetchable URLs. PDF receipts are rasterised with `pdftoppm`
before sending, since the model accepts images only.

## Web UI (M4)

| Path | Purpose |
|---|---|
| `/dashboard` | KPIs, trends, breakdowns, exports |
| `/quick-add` | docs/04 Quick Entry — confirms immediately |
| `/receipts/upload` | docs/04 Scan/Upload, camera capture on mobile |
| `/receipts`, `/receipts/{id}` | review queue and confirmation |
| `/vehicles` | FR-002 vehicle management |

Quick entry confirms in one step: unlike a receipt there is no second source to
reconcile against, so leaving it in `DRAFT` would just hide the charge from the
dashboard. The web routes reuse the API form requests, so a validation rule
cannot be enforced on one path but not the other.

---

## Insights (M5)

| Method | Path | Notes |
|---|---|---|
| POST | `/assistant/ask` | AI assistant, advisory; throttled 10/min |
| GET | `/insights/anomalies` | `?notify=1` also raises FR-014 alerts |
| GET | `/insights/forecast` | run-rate projection for the current month |

All three are **advisory** and say so in `meta`. Anomalies and forecasting are
statistical rather than AI; the assistant never produces a number at all. See
`docs/13-ai-assistant.md` for the reasoning and the guarantees.
