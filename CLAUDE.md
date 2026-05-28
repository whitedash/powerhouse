# Powerhouse — CLAUDE.md

## Mandatory before every session
1. Read this file completely
2. Run `php artisan migrate:status` and report any pending migrations
3. Run `composer audit` and `npm audit`. Report any high/critical
   vulnerabilities before proceeding.
4. Never guess column names — check migrations or run
   `php artisan db:show --table=TABLE_NAME`

## Stack
- Laravel 13 + Inertia.js + Vue 3 + Vite + Tailwind v4
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
All UI must reference the CSS variables in resources/css/app.css.
The 16 screen HTML files in /design are the source of truth
for every layout, component, and interaction pattern.

## Never do
- Never add columns not in SCHEMA.md
- Never use direct DB queries — Eloquent only
- Never put business logic in Models — use Services
- Never commit .env
- Never hardcode credentials
- Never guess column names — always check SCHEMA.md first
- **Never** use `==` or `===` to compare tokens, signatures, API
  keys, or any cryptographic value. **Always** use `hash_equals()`.
- **Never** use `$file->getClientOriginalName()` for stored filenames.
  **Never** store uploads in `public/`. **Always** route uploads
  through `App\Services\FileUploadService`.
- **Never** process a webhook without (1) verifying the signature via
  a `VerifyWebhookSignature` subclass, (2) checking idempotency via
  `WebhookIdempotencyService`, (3) excluding the route from CSRF.
- **Never** accept a URL from user input without `App\Rules\NotInternalUrl`
  in the validation chain. This is what stops SSRF.

## Key files
- SCHEMA.md — complete database schema (source of truth)
- DECISION-LOG.md — architectural decisions
- SECURITY.md — production deploy checklist + threat model
- /design/ — all 16 HTML screen designs

## Code quality
- `vendor/bin/pint` — code style (Laravel preset + extra rules)
- `vendor/bin/phpstan analyse` — static analysis, level 5

## Write operations
- Validation lives in `app/Http/Requests/*Request.php`, not in
  controllers. Each request must implement `authorize()` calling
  a policy (`$user->can('action', Model::class)`).
- All persistence inside transactions.
- Every mutation logged to `activity_log`.

## ID-handling rule (IDOR prevention)
Every controller method that accepts an ID **must**:
1. Use `findOrFail()` — never `find()`. `find()` returns null on miss
   and a null check is easy to forget.
2. Call `$this->authorizeOrFail('action', $model)` (from the
   `AuthorizesWithPolicy` trait) or `Gate::authorize(...)`.

For portal-side queries, use `Customer::forPortalUser($cid)` — never
trust an `id` from the request. For referrer-side queries, scope
every read with `where('referrer_id', auth()->user()->referrer->id)`.

## Restore to main rule
Always restore to main branch before starting a new session
unless explicitly told otherwise.
