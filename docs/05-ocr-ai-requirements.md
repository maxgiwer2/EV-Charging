# 05 OCR & AI Requirements

## OCR Output
Provider-independent adapter must return normalized fields:
- merchant/network
- station
- receipt_number
- transaction_date/time
- energy_kwh
- unit_price
- subtotal
- service_fee
- parking_fee
- discount
- vat
- total
- payment_method
- connector
- raw_text

Each extracted field has confidence 0..1.

## Review
Status:
OCR_PENDING -> OCR_PROCESSING -> OCR_REVIEW -> VERIFIED/REJECTED

Low confidence fields must be highlighted.

## Duplicate Detection
Use receipt hash plus date/time/station/amount/kWh/receipt number.

## AI Rules
- never invent missing financial values
- preserve raw OCR
- never overwrite original receipt values
- explain uncertainty
- only use authorized system data
- log AI provider/model/version
