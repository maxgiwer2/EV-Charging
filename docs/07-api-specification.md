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
