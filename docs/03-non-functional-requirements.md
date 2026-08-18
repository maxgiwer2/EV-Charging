# 03 Non-Functional Requirements

## Security
- PHP 8.3+
- prepared statements/ORM
- CSRF protection
- XSS output encoding
- authorization at route/service/query level
- rate limiting
- secure session cookies
- upload MIME/signature validation
- private receipt storage
- no secrets in repository
- audit logs
- soft delete financial records

## Performance
- indexed filters
- pagination
- async OCR jobs
- avoid N+1 queries
- dashboard aggregation caching where appropriate

## Reliability
- transactions for financial writes
- idempotent OCR callbacks/jobs
- retry with backoff
- structured logging
- backup/restore procedure

## Maintainability
- service/repository/domain separation where useful
- tests for business rules
- migrations + seeders
- `.env.example`
- API documentation
- README and deployment guide

## UX
- responsive/mobile-first
- quick entry
- scan receipt flow
- smart defaults
- clear status and validation messages
