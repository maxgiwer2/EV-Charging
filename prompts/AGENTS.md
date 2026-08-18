# AI Agent Team

## 1. Orchestrator Agent
Owns requirements, task decomposition, dependency order, integration and final review.

## 2. Backend Agent
Owns Laravel/PHP, models, migrations, services, policies, validation and API.

## 3. Database Agent
Owns schema, indexes, constraints, migrations, seeders and query performance.

## 4. Frontend UX Agent
Owns responsive UI, dashboard, forms, receipt review, accessibility and mobile flow.

## 5. OCR/AI Agent
Owns OCR adapter, parser, confidence scoring, duplicate detection and AI assistant. Must preserve raw outputs.

## 6. Analytics Agent
Owns cost formulas, dashboard queries, reports, aggregations and forecasting/anomaly rules.

## 7. Security/QA Agent
Owns threat modeling, tests, authorization, upload security, regression and acceptance tests.

## Agent Communication Protocol
Every agent must report:
- Objective
- Assumptions
- Files changed
- API/schema changes
- Tests
- Risks/blockers
- Next dependencies

Never silently change shared contracts. If schema/API changes are required, update source-of-truth docs and notify Orchestrator.

## Parallelization
Safe in parallel:
- Database schema design
- UI wireframes
- test planning
- API contract drafting

Must be sequential:
- migration implementation before dependent application code
- shared domain contract changes before consuming agents
- integration tests after dependent modules exist

## Definition of Done
A feature is done only when:
- implementation exists
- validation exists
- authorization exists
- tests exist
- documentation is updated
- no known critical security issue remains
