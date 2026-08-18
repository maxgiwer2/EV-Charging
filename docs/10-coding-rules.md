# 10 Coding Rules

1. Prefer Laravel conventions if Laravel is selected.
2. Use strict validation and typed DTOs/value objects where practical.
3. Never trust client input.
4. Financial calculations must use decimal-safe database types; avoid binary floating point for persisted money.
5. Store money as DECIMAL(12,2) and energy/rates with appropriate precision.
6. Keep original receipt/OCR values immutable after verification; corrections are auditable.
7. Use UTC internally where practical and render Asia/Bangkok in UI; preserve transaction timezone when needed.
8. Use database transactions for multi-table financial writes.
9. No business constants hard-coded in controllers.
10. Controllers should orchestrate, not contain complex business rules.
11. Add unit tests for cost/tariff/duplicate rules.
12. Add feature tests for authorization and critical flows.
13. Never log secrets, passwords, receipt contents, or tokens.
14. API errors use stable error codes.
15. All destructive operations on financial records use soft delete.
