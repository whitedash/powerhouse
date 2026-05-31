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

Card primitives: `.card-head` / `.card-body` (padded header with
divider + padded body) and `.card-header` (icon + title row) are
both **global**. Do not redefine them per-namespace unless you need
genuinely different values — a missing namespaced copy used to leave
pages with zero padding.

## New page checklist
Before committing any new Vue page, verify visually:
- [ ] All cards have background + border
- [ ] All right-column panels have card styling
- [ ] Table rows have consistent spacing
- [ ] Empty states are styled
- [ ] Mobile: no overflow-x on body
- [ ] Dropdowns: no overflow:hidden on parents
- [ ] Run: npm run build — check for warnings

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
- **Never** use `window.confirm()`, `window.alert()`, or
  `window.prompt()`. ALL confirmation dialogs must use the
  `ConfirmModal` Vue component at
  `resources/js/Components/UI/ConfirmModal.vue` (v-model:show,
  variant=danger|warning|primary, emits @confirm).
- **Dropdown clipping rule.** Never add `overflow:hidden` to
  `.card`, `.table-card`, or any container that may host a `···`
  dropdown popover. `border-radius` clips backgrounds and borders
  without `overflow:hidden`. If clipping is genuinely needed for a
  specific element inside a card (image, progress bar, fill marquee),
  apply `overflow:hidden` to **that element** — or use the
  `.card-clip` utility class on the wrapper. We removed four
  per-namespace `.X .card { overflow: visible }` overrides because
  the root cause was a global default that didn't need to exist.

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

## Nav badge cache keys
Sidebar badges are notification signals, not stats. Counts are cached
(60s TTL) and shared via `HandleInertiaRequests::share().nav`. Whenever
a controller changes a status that could affect a badge, forget the
relevant key — stale counts beat fresh ones for ~60s anyway, but a
just-resolved overdue invoice should disappear *immediately* not after
a coffee break:

| Key | Trigger to forget |
|---|---|
| `nav.invoices_overdue` | any invoice status change |
| `nav.invoices_outstanding` | any invoice status change |
| `nav.support_sla_breached` | any ticket status / SLA change |
| `nav.support_open` | any ticket status change |

Rule: `Cache::forget('nav.invoices_overdue')` etc. inside the
controller transaction *before* the response returns. Pair invoice
keys; pair support keys.

## Restore to main rule
Always restore to main branch before starting a new session
unless explicitly told otherwise.
