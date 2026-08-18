# 02 Functional Requirements

## FR-001 Authentication
Login, logout, password hashing, session security, RBAC.

## FR-002 Vehicles
CRUD รถหลายคัน: make, model, trim, year, plate, VIN(optional), battery_kwh, AC/DC max power, initial/current odometer, active status.

## FR-003 Charging Sessions
เก็บ date/time, vehicle, type, network, station, connector, AC/DC, power_kw, SOC before/after, energy_kwh, meter readings, duration, odometer, trip data, tariff snapshot, totals.

## FR-004 Receipts
Upload JPG/JPEG/PNG/WEBP/PDF, validate MIME/size, hash file, private storage, link to charging session.

## FR-005 OCR
Pipeline: upload -> preprocess -> OCR -> parser -> confidence -> duplicate check -> review -> confirm.
ห้าม auto-confirm OCR

## FR-006 Stations
CRUD station/network/connector, location, address, power, operating status.

## FR-007 Tariffs
Versioned tariff with effective_from/effective_to, peak/off-peak, AC/DC, power bands, promotions. Historical records must remain reproducible.

## FR-008 Home Charging
รองรับ MEA/PEA/other, normal/TOU, FT, VAT, solar/grid split in future.

## FR-009 Cost Calculation
คำนวณ total cost, cost/kWh, cost/km, kWh/100km, km/kWh, cost/100km.
Source priority: verified receipt/charger reading > manual energy > SOC estimate.

## FR-010 Dashboard
Monthly/yearly spend, sessions, kWh, average cost/kWh, cost/km, distance, efficiency; charts by date/month/network/station/home-public/AC-DC.

## FR-011 Reports
Daily/monthly/yearly, vehicle, station, network, energy, efficiency, receipt audit.

## FR-012 Export
CSV/XLSX/PDF with filters and date range.

## FR-013 Budget
Monthly budget, thresholds 50/80/100%, configurable.

## FR-014 Notifications
OCR review, duplicate, anomalous expense, budget threshold.

## FR-015 Audit
Record actor, action, entity, before/after, timestamp, IP, user agent.

## FR-016 Import
CSV/XLSX with column mapping, validation and duplicate detection.

## FR-017 AI Assistant
Answer from system data only; include source records in response.

## FR-018 Analytics
Compare home/public, stations, peak/off-peak, trends, forecasts and anomaly detection.
