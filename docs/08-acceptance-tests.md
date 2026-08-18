# 08 Acceptance Tests

## AT-001 Vehicle
Given authorized user, when valid vehicle data is submitted, then vehicle is persisted and visible.

## AT-002 Charging
Given a vehicle, when a valid charging session is entered, then totals and derived metrics are calculated.

## AT-003 Receipt
Given valid receipt file, when uploaded, then file is privately stored, hashed and linked.

## AT-004 OCR Review
Given OCR output, system must show extracted values and confidence; user must confirm before financial record becomes VERIFIED.

## AT-005 Duplicate
Given same receipt uploaded twice, system must flag probable duplicate.

## AT-006 Tariff
Given tariff versions with effective dates, historical session must retain its tariff snapshot.

## AT-007 Security
Unauthorized user cannot access another user's private receipts or data.

## AT-008 Export
Given filters, exported data must match filtered records.

## AT-009 Dashboard
Dashboard totals must reconcile with confirmed charging sessions.

## AT-010 Audit
Create/update/delete/verify actions must generate audit records.
