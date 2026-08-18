# EV Charging Expense & Cost Analytics System

ชุดเอกสารสำหรับใช้เป็น Source of Truth ให้ Claude Code / AI Agent พัฒนาระบบบันทึกและวิเคราะห์ค่าใช้จ่ายการชาร์จรถยนต์ไฟฟ้า

## โครงสร้าง
- `docs/01-project-overview.md` — ภาพรวมและขอบเขต
- `docs/02-functional-requirements.md` — Functional Requirements
- `docs/03-non-functional-requirements.md` — Security/Performance/Quality
- `docs/04-user-flows.md` — User Flow
- `docs/05-ocr-ai-requirements.md` — OCR/AI
- `docs/06-reporting-analytics.md` — Dashboard/Analytics
- `docs/07-api-specification.md` — API
- `docs/08-acceptance-tests.md` — Acceptance Criteria
- `docs/09-development-roadmap.md` — Milestones
- `docs/10-coding-rules.md` — Coding Rules
- `database/schema.sql` — MySQL 8+ schema
- `database/erd.md` — Mermaid ERD
- `architecture/system-architecture.md` — System Architecture
- `docs/11-development-setup.md` — Development setup และ quality gates
- `prompts/CLAUDE_CODE_MASTER_PROMPT.md` — Prompt หลักสำหรับ Claude Code
- `prompts/AGENTS.md` — Agent roles และ workflow

## Quick Start

ระบบทั้งหมดรันบน Docker (ไม่ต้องติดตั้ง PHP/MySQL บนเครื่อง) — ดูรายละเอียดใน
`docs/11-development-setup.md`

```bash
cp .env.example .env
```

```bash
docker compose up -d --build
```

```bash
docker compose exec app php artisan key:generate && docker compose exec app php artisan migrate
```

เปิด http://localhost:8080

## Stack

Laravel 12 / PHP 8.4 / MySQL 8.4 / Redis 7 / nginx — Blade + Tailwind + Alpine
สำหรับ UI และ REST API ที่ `/api/v1` (Sanctum)

## Development Status

| Milestone | Status |
|---|---|
| M0 Foundation | ✅ Docker stack, Laravel 12, CI, Pint + Larastan + Pest |
| M1 Core | ✅ Auth/RBAC + policies, 14 tables, vehicles/networks/stations/connectors/sessions, audit trail |
| M2 Receipts/OCR | ✅ Private storage, magic-byte validation, OCR adapter, duplicate detection, review UI |
| M3 Analytics | ⬜ ถัดไป |
| M4 Tariff | ⬜ |
| M5 AI | ⬜ |
| M6 Production | ⬜ |

## หลักสำคัญ
1. Receipt Original Value ห้ามถูกเขียนทับด้วยค่าที่คำนวณ
2. Tariff ต้อง versioned และมี effective date
3. OCR ต้องผ่าน human review ก่อน Confirm
4. รายการทางการเงินใช้ Soft Delete
5. Business rules ห้าม hard-code
6. ทุก milestone ต้องผ่าน test ก่อนเริ่ม milestone ถัดไป
