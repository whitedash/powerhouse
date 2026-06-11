# Powerhouse — CLAUDE.md

Apostolos's operating layer (never commercialised): CRM, invoicing, billing, support,
referrals, OAuth identity provider for all Whitedash products.

## Mandatory before every session

1. Read this file completely.
2. Session-start checks + the full gate (Pint, PHPStan level 5, PHPUnit, clean build,
   audits): **laravel-quality-gates skill**.
3. Never guess column names — SCHEMA.md is the source of truth; or
   `php artisan db:show --table=NAME`.

## Stack

Laravel 13 + Inertia.js + Vue 3 + Vite + Tailwind v4 · Laravel Passport (OAuth 2.0
server) · MySQL. Deploys per the **cpanel-laravel-deploy skill** (this app HAS
Settings → Deployment — use it).

## Naming

Models PascalCase singular; controllers split by area (Internal/, Portal/,
Referrer/); services verb-noun (InvoiceService); Vue components PascalCase.
CSS via design-system variables in `resources/css/app.css` (user-level token rule).

## Design system

The 16 HTML files in `/design` are the source of truth for every layout, component,
and interaction pattern (read-only — user-level rule).

### SECTION-PANEL RULE (mandatory) → CONVENTIONS.md "Section panels"
Every detail/section panel MUST be `<section class="card">` with
`.card-header`/`.card-head` + a padded body; empty states/footers/action rows live
INSIDE the padded body. Never hand-roll a section container. Grep guard:
`composer audit:sections`. Full spec, DO/DON'T, and the deliberate exceptions list
are in **CONVENTIONS.md** — read it before adding any panel.

### NAMESPACING RULE (mandatory) → CONVENTIONS.md
Any CSS rule overriding a shared primitive (`.card-*`, `.form-*`, `.table-*`,
`.badge-*`, `.btn-*`) MUST be scoped to a page/component namespace. Unscoped override
= stop and add the namespace.

## New page checklist

Cards have bg+border; right-column panels carry card styling; consistent row spacing;
styled empty states; mobile: no body overflow-x; dropdowns: no `overflow:hidden` on
parents; `npm run build` warning-free.

## Never do (security — keep verbatim)

- Never add columns not in SCHEMA.md.
- Never use direct DB queries — Eloquent only.
- Never put business logic in Models — use Services.
- Never commit `.env`. Never hardcode credentials.
- **Never** use `==`/`===` to compare tokens, signatures, API keys, or any
  cryptographic value — **always** `hash_equals()`.
- **Never** use `$file->getClientOriginalName()` for stored filenames. **Never**
  store uploads in `public/`. **Always** route uploads through
  `App\Services\FileUploadService`.
- **Never** process a webhook without (1) signature verification via a
  `VerifyWebhookSignature` subclass, (2) idempotency via
  `WebhookIdempotencyService`, (3) CSRF route exclusion.
- **Never** accept a user-supplied URL without `App\Rules\NotInternalUrl` (SSRF).
- **Never** use `window.confirm/alert/prompt` — all confirmations via the
  `ConfirmModal` component (`resources/js/Components/UI/ConfirmModal.vue`).
- **Dropdown clipping:** never `overflow:hidden` on `.card`/`.table-card`/any
  container hosting a `···` popover; clip the specific inner element or use
  `.card-clip`.

## ID-handling rule (IDOR prevention)

Every controller method accepting an ID: `findOrFail()` (never `find()`) +
`$this->authorizeOrFail('action', $model)` / `Gate::authorize`. Portal queries via
`Customer::forPortalUser($cid)` — never trust a request id. Referrer reads scoped
`where('referrer_id', auth()->user()->referrer->id)`.

## Write operations

Validation in `app/Http/Requests/*Request.php` with `authorize()` calling a policy;
all persistence in transactions; every mutation logged to `activity_log`.

## Nav badge cache keys

Badges are cached 60 s via `HandleInertiaRequests::share().nav`. On any status change
that affects a badge, `Cache::forget` the paired keys inside the controller
transaction before responding: `nav.invoices_overdue` + `nav.invoices_outstanding`
(any invoice status change); `nav.support_sla_breached` + `nav.support_open`
(any ticket status/SLA change).

## Restore to main rule

Always restore to the main branch before starting a new session unless told otherwise.

## POWERHOUSE PROJECT SYNC (keep verbatim)

Every sprint syncs with Powerhouse PM via `.powerhouse.json` (gitignored,
per-developer; copy the `.example`). Bridges: `task:sync`, `task:export`,
`task:update`, `task:status`.

- Session start: `cat .powerhouse.json` → `php artisan task:status` → if TASKS.md
  exists, `task:sync --dry-run`, review, then sync. Never assume a task is to-do —
  task:status is live state.
- During sprint: `task:update {id} in_progress|complete|blocked --reason="…"`.
- Session end: `task:export` → commit SPRINT-STATUS.md.
- NEVER guess a project_id — read `.powerhouse.json`; if missing, stop and ask.
  task:sync matches by title and skips existing — safe to re-run.

## Key files

SCHEMA.md (DB source of truth) · DECISION-LOG.md · SECURITY.md (deploy checklist +
threat model) · CONVENTIONS.md (frontend detail) · RUNBOOKS/ · /design/.
