# DocRenew

Employee Document Renewal Management System — tracks expiry/renewal dates for employee documents (passports, visas, work permits, licenses, certifications) and extended items (warranties, vehicle maintenance, bills, AMCs, other notifications), with escalating email/SMS/push reminders.

## Stack
- Frontend: Angular 21 (CLI 21.2.19), PrimeNG
- Backend: Laravel / PHP 8.3
- Database: MySQL 8
- Hosting: DigitalOcean (App Platform / Droplets, Managed Databases, Spaces)

## Structure
```
DocRenew/
├── .github/workflows/   CI (GitHub Actions)
├── backend/             Laravel API
├── db/                  Reference schema (schema.sql)
├── Documentation/       BRD, FSD, mockups, time estimate, Jira sheet
├── frontend/            Angular app
├── infra/               Deployment / Docker configs
├── .env.example
└── docker-compose.yml   Local dev stack (backend, frontend, mysql, redis)
```

## Getting started
1. Copy `.env.example` to `.env` and fill in secrets.
2. `docker-compose up` to start MySQL, Redis, backend, and frontend locally.
3. See `Documentation/` for the full BRD, FSD, and database schema reference.
