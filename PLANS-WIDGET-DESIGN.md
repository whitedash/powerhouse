# Plans Widget — Design (pre-implementation)

Status: **DESIGN ONLY — not implemented.** Investigation completed 2026-07-06;
decisions below marked LOCKED were confirmed by Apostolos, everything under
"Open questions" is still his call. On implementation: add the migration,
update SCHEMA.md in the same commit, and append a DECISION-LOG.md row
(draft at the bottom of this file).

## Summary

A public, embeddable "Plans" widget — same script-snippet pattern as the
forms widget (`/forms/{slug}/embed.js`) — that lets an anonymous visitor on
an external site pick a plan, pay via Stripe Checkout, and have Powerhouse
automatically: provision Company/Person/Contact, create a CustomerProduct,
create an invoice, mark it paid, and email a purchase receipt.

Locked scope decisions:

- **One-time purchases only** (existing Checkout `mode: 'payment'`; no
  Stripe Subscriptions in v1). Recurring plans are invoiced later by the
  existing `auto_invoice` machinery if/when enabled — out of scope here.
- **Provisioning happens at webhook time** (`checkout.session.completed`),
  driven entirely by session metadata. Nothing is written to the DB at
  checkout-initiation, so abandoned checkouts leave zero orphan rows.
- **Per-plan review gate**: new `product_plans.requires_manual_review`
  boolean (default false). `false` = fully live immediately; `true` =
  records created but held "pending" (defined in §4) until a human confirms.
- **Email collision**: auto-link to existing Person via the current
  `PersonService::createOrLinkFromContact` dedup behaviour, unchanged.
- **Receipt**: a NEW purchase-specific mailable. `PaymentReceived` and the
  shared Stripe settlement path (`StripeService::markInvoicePaid`) keep
  their current behaviour for all existing payments.

## 1. Schema delta

Exactly one column:

```
product_plans
  + requires_manual_review BOOLEAN NOT NULL DEFAULT false
    -- Plans widget: when true, a self-serve purchase provisions records
    -- in a held "pending" state (customer_products.status='pending',
    -- receipt withheld) until a staff member confirms. Default false =
    -- purchase goes live immediately on webhook settlement.
```

Migration: `add_requires_manual_review_to_product_plans_table`. SCHEMA.md
updated in the same commit (product_plans block, lines ~135–159).

**Confirmed: no other schema changes needed.** Everything else the flow
touches already exists as documented in SCHEMA.md:

| Need | Existing coverage |
|---|---|
| Widget identifier | `products.slug` VARCHAR(50) UNIQUE — widget is per-product |
| What to show | `product_plans.is_public` + `is_active`, `product_plan_prices.is_active` + `interval_unit='one_time'` (or any interval sold as a one-off first period — see Open Q9) |
| Purchase context between checkout-init and webhook | Stripe Checkout Session `metadata` (plan_price_id, purchaser name/email, company name, ref) — no DB row needed pre-payment |
| Subscription record | `customer_products` (has `plan_id`, `plan_price_id`, `status` ENUM incl. `pending`) |
| Invoice ↔ catalog link | `invoice_lines.product_id` / `plan_id` (nullable FKs, already there) |
| Stripe reconciliation | `invoices.stripe_checkout_session_id` / `stripe_payment_intent_id` / `paid_via` |
| Payment ledger | `payments` with UNIQUE `stripe_payment_intent_id` (upsert-safe) |
| Webhook idempotency | `webhook_events` UNIQUE(source, event_id) |
| Attribution | `customers.acquisition_channel` already has `landing_page`; referral via `customer_referrals` pivot |
| System-actor audit | `activity_log.user_id` nullable, `user_role='system'` precedent (StripeService) |

## 2. Service extraction — CompanyProvisioningService

Today the Company + primary Contact + Person assembly lives inline in two
controllers: `Internal\CompanyController::store()` (~876–951) and
`Internal\LeadController::convert()` (~410–546). Both end by calling
`PersonService::createOrLinkFromContact()` + `attachCompany()`. A webhook
cannot call a controller, so extract:

**New `App\Services\CompanyProvisioningService`** (noun convention, like
InvoiceService/PersonService):

```
provision(array $data, ?User $actor): ProvisionResult
  // $data: company name, contact name, email, phone?, type?,
  //        acquisition_channel, channel_detail?, referrer?
  // Returns: { company, person, contact, companyWasCreated, personWasCreated }
```

Behaviour (all inside the caller's transaction — the service does NOT open
its own, matching PersonService):

1. Resolve Person via `PersonService::createOrLinkFromContact()` — dedup by
   normalised email, unique-constraint race recovery, unchanged (LOCKED).
2. Company resolution: if the deduped Person already has linked companies
   via `customer_person`, reuse the existing Company rather than creating a
   duplicate (LOCKED "auto-link to existing Person/Company"). Multi-company
   persons: see Open Q3. Otherwise create Company + primary Contact exactly
   as CompanyController::store does today.
3. Link via `PersonService::attachCompany()` (`syncWithoutDetaching`, idempotent).
4. Activity log rows for each created entity, attributed to `$actor` or
   `user_id=null / user_role='system'` when null.

**Signature change required in PersonService**: `createOrLinkFromContact()`
and `attachCompany()` currently type-hint `User $actor` (non-nullable).
Relax both to `?User $actor = null`; when null, log `user_role='system'`
(same convention as `StripeService::markInvoicePaid`, StripeService.php
~559–573). Only two existing callers (the two controllers above, verified
by grep) — both keep passing `$request->user()`, so no behaviour change
for authenticated paths.

**Refactor, not rewrite**: CompanyController::store() and
LeadController::convert() switch to calling the service; their Gates,
FormRequests, referral attribution, and lead-migration logic stay in the
controllers. Authorization remains a route/controller concern — the service
carries none (consistent with every existing service).

This extraction is also the natural landing zone for the planned
dedup-hardening sprint (atomic firstOrCreate-by-email funnel for all
contact paths) — same funnel, one owner.

## 3. New public endpoints

### 3a. `GET /plans/{slug}/embed.js`

Mirror of `EmbedController::script()` for forms:

- `{slug}` = `products.slug`, constraint `[a-z0-9-]+`.
- Controller: `Public\PlanEmbedController::script()`. Loads the active
  product by slug; serialises ONLY plans where `is_public && is_active`,
  each with its active prices (label, formatted GBP amount, interval label,
  `is_default`, features JSON). Non-public plans never reach the payload —
  same public-IDOR stance as the KB (`is_public && is_published` scoping).
- Response: Blade-compiled IIFE (`resources/views/embed/plans-widget.blade.php`)
  served as `application/javascript`, `Cache-Control: public, max-age=300`,
  `X-Content-Type-Options: nosniff`.
- Middleware: the existing `forms.cors` group (`FormCors` — wildcard origin,
  credential-less). Reuse as-is; optionally register a second alias
  `embed.cors` pointing at the same class so the name stops being
  forms-specific (cosmetic, zero behaviour change).
- Host-page snippet, same shape as forms:
  ```html
  <div id="pw-plans-{slug}"></div>
  <script src="https://hub.whitedash.com/plans/{slug}/embed.js"></script>
  ```
- OPTIONS preflight routes alongside, copying the forms group
  (routes/web.php ~1011–1027 pattern).

### 3b. `POST /plans/{slug}/checkout` — anonymous checkout initiation

The genuinely new surface: an anonymous POST that costs a Stripe API call.

- Input: `plan_price_id`, `name`, `email`, `company_name` (optional),
  `_hp` honeypot, optional `ref` (referral code, same resolution as forms).
- Validation (FormRequest, `authorize()` returns true — public):
  - `plan_price_id` must belong to a plan of THIS product (route slug),
    with plan `is_public && is_active` and price `is_active`. Resolved
    server-side; **the client never supplies an amount** — the charge total
    comes from `product_plan_prices.price`, mirroring how the existing
    checkout builder derives amounts from the invoice server-side.
  - email: `email:rfc`, name/company_name: bounded strings.
- Abuse controls, layered like forms + support:
  - honeypot `_hp` → silent fake success (forms pattern);
  - per-IP-per-slug `RateLimiter` (propose 10/hour — stricter than form
    drafts because each hit is a Stripe API call);
  - route `throttle:` tier (propose `throttle:12,1`, POST-tier like
    proposals' 6/min but allowing plan-switch retries);
  - Turnstile: see Open Q8 — the `/support` endpoint is the precedent for
    "public endpoint worth more than a honeypot".
- Action: create a Stripe Checkout Session, `mode: 'payment'`, hosted
  (redirect) for v1 — no Stripe.js needed on the host page (embedded
  `ui_mode` is Open Q7). Metadata:
  `{ plan_price_id, purchaser_name, purchaser_email, company_name, ref }`
  (all well under Stripe's 500-char/value limit).
  `success_url`: a new Powerhouse-hosted public thank-you page
  (`GET /plans/{slug}/purchased?session_id=…`, throttled, no DB writes —
  provisioning is webhook-only). `cancel_url`: back to the host page —
  **user-supplied URL, so `App\Rules\NotInternalUrl` is mandatory** on it
  (SSRF rule), plus http/https scheme restriction; fallback to the
  thank-you page's product URL if absent (Open Q10).
- Response: JSON `{ url }` for the widget to redirect to.
- CSRF: add `plans/*/checkout` to the `validateCsrfTokens(except:)` list in
  bootstrap/app.php, with a comment block matching the forms one
  (compensating controls listed).

No DB writes in this endpoint. A failed/abandoned session costs nothing
to clean up.

## 4. Webhook extension + the "pending" state

### Branching

`StripeWebhookController::handleCheckoutCompleted()` gains one branch at
the top, keyed on metadata:

- `metadata.invoice_id` present → **existing path, byte-for-byte unchanged**
  (invoice settlement via `StripeService::markInvoicePaid()`).
- `metadata.plan_price_id` present (and no invoice_id) → **new plan-purchase
  path** (below).
- Neither → log + 200 (unknown session, matches current defensive stance).

Signature verification (`VerifyStripeWebhook`), idempotency
(`WebhookIdempotencyService` over `webhook_events`), CSRF exclusion
(`webhooks/*`), and `throttle:120,1` are all reused verbatim — no new
webhook endpoint.

### Plan-purchase path (proposal-accept structure: one transaction, binding
core; per-component try/catch for non-critical extras; mail after commit)

Inside `DB::transaction()`:

1. **Replay guard beyond webhook_events**: abort-noop if an invoice already
   exists with this `stripe_checkout_session_id` (idempotent re-entry, same
   belt-and-braces as markInvoicePaid's locked status re-check).
2. Resolve `ProductPlanPrice` (+ plan + product) from metadata; re-verify
   `is_public/is_active` and that the paid amount matches the price
   (defence against stale sessions after a price edit — mismatch → log,
   flag, still record the payment truthfully; see Open Q11).
3. `CompanyProvisioningService::provision($metadata-derived data, actor: null)`
   — Person dedup + Company create-or-reuse per §2.
   `acquisition_channel='landing_page'`, `channel_detail='plans-widget:{product-slug}'`.
4. Create `CustomerProduct`: plan/price FKs, and
   `status = requires_manual_review ? 'pending' : 'active'` — **this enum
   value already exists**; it is the single carrier of the pending state.
5. Create Invoice: number via `Invoice::generateNextNumber()` (pessimistic
   lock, inside this transaction as required), one `InvoiceLine` linked to
   `product_id`/`plan_id`, billing entity from `products.billing_entity_id`
   (fallback: Open Q12), VAT per billing-entity registration (existing
   rule: non-registered forces 0). Created `status='sent'`, then settled by
   calling **`StripeService::markInvoicePaid($invoice, sessionId, piId)`**
   so there is exactly one settlement code path: payments-ledger upsert by
   PI, `invoice.paid` activity row (`user_role='system'`), nav-cache
   forgets, commission accrual (see Open Q5 — reusing markInvoicePaid means
   `CommissionService::accrueForInvoice()` fires; if self-serve purchases
   must NOT accrue, that needs an explicit opt-out flag on the call).
6. Activity rows for company/person/customer_product creation are written
   by the service/handler with `user_id=null, user_role='system',
   user_agent='stripe-webhook'` (existing convention).

After commit:

- **Live path** (`requires_manual_review=false`): send the purchase receipt
  (§5) to the Person's email; in-app staff notification
  ("Plan purchased: {plan} — {company}") via NotificationService.
- **Pending path** (`requires_manual_review=true`), concretely:
  - Company, Person, Contact, Invoice (paid — the money IS settled, the
    ledger must say so), and CustomerProduct all exist;
  - `customer_products.status='pending'` is the flag — no new column, no
    Company-level flag (`customers` has no status column; inventing one
    duplicates state. `pipeline_stage` stays `active`: they are a paying
    customer either way);
  - **receipt email is withheld**;
  - staff notification: "Plan purchase awaiting review".
  - **Confirm action** (internal, authenticated): a small endpoint/button on
    the customer-product (or a pending-purchases filter view) — Gate behind
    an existing permission (propose `companies.manage`; Open Q1) — that
    flips `status` `pending → 'active'` in a transaction, logs
    `customer_product.review_confirmed` to activity_log, and sends the
    withheld receipt. Rejection/refund path: Open Q2.

Failure isolation: steps 3–5 are binding and atomic — if any throws, the
transaction rolls back, `webhook_events` is never `markProcessed`, and
Stripe retries (existing record-before/mark-after strategy). Receipt and
notifications are post-commit, try/catch-swallowed with `report()`, exactly
like proposal-accept emails.

## 5. Receipt email — purchase-specific, shared path untouched (LOCKED)

New `App\Mail\PlanPurchaseReceipt` mailable: welcome copy + plan summary +
the paid invoice PDF via the existing `InvoicePdfService` (same attachment
mechanism as `PaymentReceived`). Sent post-commit to the Person's email;
on the pending path it is deferred to the confirm action.

Explicitly NOT doing: adding `PaymentReceived` (or any mail) to
`StripeService::markInvoicePaid()` — that would change behaviour for every
existing Stripe-paid invoice. The shared settlement path remains
mail-silent; the plan-purchase webhook branch owns its own mail.

## 6. Open questions (Apostolos to decide — nothing below is assumed)

1. **Pending-review UX + permission.** Where does the reviewer see pending
   purchases (a filtered customer-products list, a dashboard card, a nav
   badge?) and which permission gates the confirm action
   (`companies.manage` proposed)? Should a nav badge cache key be added
   (would need the paired `Cache::forget` discipline per CLAUDE.md)?
2. **Rejection path for pending purchases.** If review says no: Stripe
   refund (API call) + invoice `void` + payments-ledger row for the
   refund + CustomerProduct `cancelled`? Or is "reject" out of scope for
   v1 (confirm-only, refunds handled manually in Stripe dashboard)?
3. **Existing person with MULTIPLE linked companies.** §2 reuses the
   company when the deduped person has exactly one. With >1: reuse the
   most recently attached, or create a new company and flag for review?
   (Recommend: create new + flag — cheapest to merge later, no wrong guess.)
4. **Portal access timing.** Invite the new Person to the portal
   immediately on purchase, on review-confirm, or leave it to staff
   manually? (Nothing in the current provisioning path creates portal
   credentials; `portal_users` exists and is separate from `people`.)
5. **Referral commission auto-accrual.** Reusing `markInvoicePaid` means
   `CommissionService::accrueForInvoice()` fires on self-serve purchases
   automatically. Desired? If not, the call needs an accrual opt-out
   parameter (small change to the shared path — weigh against §5's
   "don't touch shared path" stance).
6. **Company name when the purchaser gives none.** `company_name` optional
   → default to the person's own name, or make it required in the widget?
7. **Hosted redirect vs embedded Checkout.** v1 proposes hosted redirect
   (simplest, no Stripe.js on host pages). Is the embedded `ui_mode`
   (already supported by `createEmbeddedCheckoutSession`) wanted for v1.1
   so the visitor never leaves the marketing site?
8. **Turnstile on checkout-init?** Honeypot + rate limits (forms precedent)
   vs Turnstile (support-endpoint precedent). Each bot hit costs a Stripe
   API call, which argues for Turnstile; it adds a widget dependency on
   the host page.
9. **Selling recurring-interval prices as one-offs.** Does the widget list
   ONLY `interval_unit='one_time'` prices in v1, or also recurring prices
   sold as "first period paid now" (CustomerProduct then carries
   `auto_invoice`/`next_billing_date` for the existing recurring
   machinery)? The latter is more useful but widens v1.
10. **Cancel/return URL policy.** Accept the host page URL (with
    `NotInternalUrl` + scheme checks) or refuse user-supplied URLs entirely
    and always land on the Powerhouse thank-you page?
11. **Amount-mismatch handling.** If a session settles for an amount that
    no longer matches the current price (price edited mid-checkout):
    provision at the paid amount + flag, or hold as pending regardless of
    `requires_manual_review`?
12. **Billing-entity fallback.** `products.billing_entity_id` is nullable —
    when null, use which entity? (A "default entity" concept doesn't exist
    in SCHEMA.md; simplest: make the column required for widget-enabled
    products and validate at embed time.)
13. **Widget theming.** Reuse the `form_themes` token system
    (`FormThemeTokens::resolve()`) for the plans widget, or ship v1 with a
    single static token set?

## DECISION-LOG.md draft row (append on implementation, not before)

> | Jul 2026 | Public Plans widget (design) | Embeddable per-product plans
> widget reusing the forms embed mechanism (script IIFE + FormCors + CSRF
> exemption + honeypot/rate-limit), Stripe hosted Checkout `mode=payment`
> one-offs only. Provisioning is webhook-only from session metadata (zero
> orphans on abandonment): new `CompanyProvisioningService` extracted from
> CompanyController::store/LeadController::convert with nullable/system
> actor (PersonService actor param relaxed to `?User`); CustomerProduct +
> paid invoice created in the webhook transaction, settled through the
> existing `StripeService::markInvoicePaid()` single path. Per-plan
> `product_plans.requires_manual_review` gates live-vs-pending; "pending" =
> `customer_products.status='pending'` + withheld receipt, human confirm
> flips it. Email dedup collision policy: auto-link via existing
> PersonService behaviour, unchanged. Receipt is a new purchase-specific
> mailable; shared Stripe settlement path stays mail-silent. Full design:
> PLANS-WIDGET-DESIGN.md. |
