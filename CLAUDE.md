# Powerhouse — CLAUDE.md

## Mandatory before every session
1. Read this file completely
2. Run `php artisan migrate:status` and report any pending migrations
3. Never guess column names — check migrations or run
   `php artisan db:show --table=TABLE_NAME`

## Stack
- Laravel 11 + Inertia.js + Vue 3 + Vite + Tailwind
- Laravel Passport (OAuth 2.0 server)
- MySQL

## Naming conventions
- Models: PascalCase singular (Customer, Invoice, CommissionLedger)
- Controllers: split by area (Internal/, Portal/, Referrer/)
- Services: verb-noun (InvoiceService, CommissionService)
- Vue components: PascalCase (CustomerDetail.vue)
- CSS: use design system variables only (--accent, --border etc)
  Never hardcode hex values.

## Design system
All UI must reference the CSS variables in resources/js/app.css.
The 16 screen HTML files in /design are the source of truth
for every layout, component, and interaction pattern.

## Never do
- Never add columns not in SCHEMA.md
- Never use direct DB queries — Eloquent only
- Never put business logic in Models — use Services
- Never commit .env
- Never hardcode credentials
- Never guess column names — always check SCHEMA.md first

## Key files
- SCHEMA.md — complete database schema (source of truth)
- DECISION-LOG.md — architectural decisions
- /design/ — all 16 HTML screen designs

## Restore to main rule
Always restore to main branch before starting a new session
unless explicitly told otherwise.
