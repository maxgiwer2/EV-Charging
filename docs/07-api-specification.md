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
