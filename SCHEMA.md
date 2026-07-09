# Powerhouse — Database Schema
*Source of truth. Never add or remove columns without updating this file.*

## users
id, name, email, password, role ENUM(super_admin|staff|referrer),
avatar_colour, two_factor_secret TEXT (encrypted),
two_factor_confirmed_at,
last_login_at, last_login_ip, created_at, updated_at
-- plus (2026_05_31): notification_preferences JSON nullable —
   per-user notification opt-in/out map; null = all defaults
   (User::wantsNotification() treats a missing key as true).
-- plus (2026_06_02): google_access_token TEXT nullable (encrypted),
   google_refresh_token TEXT nullable (encrypted),
   google_token_expires_at nullable, google_calendar_id nullable,
   google_sync_enabled BOOLEAN DEFAULT false — per-user Google
   Calendar connection; tokens encrypted via the model cast
   (TEXT because ciphertext overflows varchar 255); null
   calendar_id means 'primary'.

## customers
id, name, trading_name, company_number, vat_number,
type ENUM(restaurant|bar|bakery|cafe|venue|other),
address_line1, address_line2, city, postcode, country,
billing_address JSON nullable,
pipeline_stage ENUM(lead|prospect|active|churned),
acquisition_channel ENUM(direct|google|social_media|landing_page|
  referral|email|event|word_of_mouth|other) nullable
  -- How the lead arrived. Surfaced on the customer header.
channel_detail VARCHAR(255) nullable
  -- Free-text follow-up (campaign name, platform, event, etc.).
assigned_to BIGINT FK users nullable,
-- referred_by: DROPPED 2026-06-03. Was a dead, FK-less, never-read
--   column; canonical attribution is the customer_referrals pivot.
qbo_customer_id VARCHAR(100) nullable UNIQUE
  -- QuickBooks Online customer id. Populated by a future QBO sync.
exempt_from_auto_suspend BOOLEAN NOT NULL DEFAULT false
  -- When true the invoices:process-suspensions sweep skips this
  -- customer (Webhooks + Auto-Suspension sprint).
exempt_reason VARCHAR(500) nullable
auto_collect BOOLEAN DEFAULT false
  -- Billing P1: per-customer auto-collect intent. Stored/toggled only;
  -- P2 charges the default saved card off-session when true.
erasure_requested_at TIMESTAMP nullable
  -- GDPR Art. 17: when a right-to-erasure request was logged.
erasure_completed_at TIMESTAMP nullable
  -- When the anonymisation actually ran (after invoices settled).
erasure_requested_by BIGINT FK users nullable ON DELETE SET NULL
data_export_last_at TIMESTAMP nullable
  -- GDPR Art. 20: last right-to-portability export timestamp.
archived_at nullable, created_at, updated_at
-- plus (2026_05_30): portal_last_login_at TIMESTAMP nullable,
   portal_login_count INT UNSIGNED DEFAULT 0 — account-level portal
   login aggregate (SSO sprint); PortalAuthController::login() bumps
   both on every successful portal login, so SSO/OAuth flows need no
   extra hook.

## contacts
id, customer_id FK, name, email, phone nullable,
role ENUM(owner|manager|accounts|other),
is_primary BOOLEAN DEFAULT false, created_at, updated_at
-- plus (2026_05_29): job_title nullable(100), notes nullable;
   email is now nullable.
-- plus (2026_06_03): person_id FK->people nullable (set null) —
   optional link from an operational contact to a cross-company person.
   No backfill; one-to-many on customer_id is unchanged.
-- NOTE: contact email uniqueness is enforced PER customer_id (not
   global) in ContactController, so one person can be a contact at
   several companies.

## account_groups
id, name, created_at, updated_at
-- plus (2026_05_30): description nullable, colour nullable(7),
   created_by FK->users nullable (set null)

## customer_group_memberships
id, group_id FK, customer_id FK,
role ENUM(owner|member), created_at

## people
id, name, email nullable UNIQUE, phone nullable(50),
notes nullable, created_by FK->users nullable (set null),
created_at, updated_at
-- Cross-company human identity (the "one person owns many companies"
   layer). Sits alongside contacts; does NOT replace it. email is the
   dedupe key, so it is UNIQUE (nullable allows email-less people).

## customer_person   (pivot: person <-> the companies they own/associate with)
id, customer_id FK->customers (cascade),
person_id FK->people (cascade),
role VARCHAR(32) default 'owner', job_title nullable(100),
created_at, updated_at
UNIQUE (customer_id, person_id)
-- role is NOT a DB enum: App\Enums\PersonRole is the single source of
   truth (owner|director|shareholder|partner|manager|accounts|
   signatory|other). The CustomerPerson pivot model casts it.

## portal_users
id, customer_id FK, name, email, password,
two_factor_secret nullable, two_factor_confirmed_at nullable,
email_verified_at nullable, last_login_at nullable,
created_at, updated_at
-- plus (2026_05_29): contact_id FK->contacts nullable (set null) —
   ties a portal login to the specific contact it belongs to, so
   one customer can invite each contact as a distinct login;
   nullable = existing rows need no backfill.

## portal_password_resets
email VARCHAR(255) PRIMARY KEY, token VARCHAR(255),
created_at nullable
-- Portal twin of Laravel's password_reset_tokens, keyed separately so
   a staff and a portal reset cycle can run concurrently for the same
   email. token holds a HASH of the plaintext (never the plaintext);
   no updated_at — the controller deletes on use, and a re-request
   replaces the row.

## products
id, slug VARCHAR(50) UNIQUE, name, description,
billing_entity_id FK billing_entities nullable (SET NULL)
  -- Default billing entity for invoices that include this product;
  -- null = universal (operator picks the entity per invoice).
icon_colour, is_active BOOLEAN, is_coming_soon BOOLEAN,
sort_order INT,
qbo_item_id VARCHAR(100) nullable UNIQUE
  -- QuickBooks Online item id. Populated by a future QBO sync.
theme_id FK plan_themes nullable (SET NULL) (2026_07_07)
  -- Plans-widget theme for this product's embeds (both flavours;
  -- the single-plan embed inherits via plan -> product -> theme).
  -- NULL = default look (PlanThemeTokens::defaults()).
created_at, updated_at

## plan_themes (Plans-widget theming — form_themes mirrored)
id, name VARCHAR(255),
tokens JSON
  -- PARTIAL override set of design tokens; effective values =
  -- App\Support\PlanThemeTokens::defaults() merged with these (theme
  -- wins; absent/null falls back). Generic keys shared with form_themes
  -- (font_family, font_size, text, accent, background, surface, border,
  -- border_width, radius, button_bg, button_bg_hover, button_text,
  -- error, logo_url, heading, custom_css) + plan-specific (card_bg,
  -- card_border, card_radius, price_color, feature_check, muted).
  -- custom_css lives INSIDE tokens (like form_themes) and is gated to
  -- the manageCustomCss ability. toStripeBranding() bridges the same
  -- tokens onto Checkout branding_settings (hex-only; non-hex omitted).
created_by FK users, created_at, updated_at

## product_plan_categories
id, product_id FK CASCADE,
name VARCHAR(100), description TEXT nullable,
sort_order INT DEFAULT 0,
is_public BOOLEAN DEFAULT true,
created_at, updated_at
INDEXES: UNIQUE(product_id, name), (product_id, sort_order)

## product_plans
id, product_id FK CASCADE,
category_id FK product_plan_categories nullable ON DELETE SET NULL,
theme_id FK plan_themes nullable (SET NULL) (2026_07_08)
  -- Per-plan widget-theme OVERRIDE. Resolution chain
  -- (PlanThemeTokens::resolveForPlan): plan.theme_id, else the
  -- product's theme_id, else the default look. Drives both the
  -- rendered card and that plan's Stripe branding_settings.
name VARCHAR(100), description TEXT nullable,
features JSON nullable,
is_active BOOLEAN DEFAULT true,
is_public BOOLEAN DEFAULT true,
requires_manual_review BOOLEAN NOT NULL DEFAULT false,
  -- Plans widget (PLANS-WIDGET-DESIGN.md): when true, a self-serve
  -- purchase provisions in a held "pending" state (customer_products
  -- status='pending', receipt withheld) until staff confirm; false =
  -- purchase goes live immediately on webhook settlement.
is_hosting BOOLEAN DEFAULT false,
  -- hosting plan (drives the Websites hosting selector)
is_domain BOOLEAN DEFAULT false,
  -- domain-registration plan; the renewal PRICE source for the
  -- domains table (matched by TLD). Index (is_domain, is_active).
tld VARCHAR(20) nullable
  -- TLD this domain plan prices (".com", ".co.uk", ".gr"). Required
  -- for is_domain plans; ONE active plan per TLD (controller-enforced).
  -- A domain plan has exactly ONE active product_plan_prices tier: its
  -- interval IS the renewal duration, its price IS the renewal price
  -- (no separate renewal-term column — controller-enforced).
sort_order INT DEFAULT 0,
disk_quota_gb SMALLINT UNSIGNED nullable,
email_quota SMALLINT UNSIGNED nullable,
bandwidth_quota_gb SMALLINT UNSIGNED nullable,
  -- Hosting allowances (Websites sprint). Nullable; only hosting plans.
created_at, updated_at
INDEXES: (product_id, is_active, sort_order)

## product_plan_prices
id, plan_id FK product_plans CASCADE,
price DECIMAL(10,2) DEFAULT 0,
setup_fee DECIMAL(10,2) nullable (2026_07_09)
  -- Plans widget "setup fee + recurring": when set on a RECURRING
  -- (non-one_time) price, a plan purchase charges this fee immediately
  -- and provisions an auto_invoice=true customer_product that the
  -- subscription sweep bills at `price` on cadence. NULL/0 = today's
  -- behaviour (one-off charge of `price`, non-recurring). "one_time
  -- prices may not carry a setup_fee" is controller-enforced (like the
  -- one-active-domain-plan-per-TLD rule), not a DB CHECK.
interval_count TINYINT UNSIGNED DEFAULT 1,
interval_unit ENUM(day|week|month|year|one_time) DEFAULT 'month',
stripe_price_id VARCHAR(100) nullable,
label VARCHAR(100) nullable,
is_default BOOLEAN DEFAULT false,
is_active BOOLEAN DEFAULT true,
sort_order INT DEFAULT 0,
created_at, updated_at
INDEXES: (plan_id, is_active, sort_order)

## plan_checkout_attempts (Plans widget — abandoned-checkout tracking)
id, plan_price_id FK product_plan_prices nullable (SET NULL),
purchaser_name VARCHAR(255), purchaser_email VARCHAR(255),
purchaser_company VARCHAR(255) nullable (2026_07_08),
purchaser_phone VARCHAR(50) nullable (2026_07_08),
  -- Optional step-1 fields, captured for abandoned-checkout follow-up.
stripe_checkout_session_id VARCHAR(100) UNIQUE,
status ENUM(pending|completed|abandoned) DEFAULT 'pending',
started_at TIMESTAMP, completed_at TIMESTAMP nullable,
abandoned_at TIMESTAMP nullable,
created_at, updated_at
INDEX (status, started_at)
-- The ONLY table the public checkout-init endpoint writes (the
-- no-Company/Contact/Person/Invoice-at-init guarantee stands). Webhook
-- settlement marks completed by session id (a late completion overrides
-- abandoned — sessions stay payable to their 24h Stripe expiry);
-- plans:reconcile-abandoned-checkouts flips stale pending rows to
-- abandoned after the window (default 24h) and emails staff once.

## customer_products
id, customer_id FK, product_id FK,
-- External provisioning (2026_06_03): consumer apps Powerhouse creates
-- the account in (currently MyOrderPad); the SSO token carries
-- external_user_id so the consumer resolves the account on auto-login.
external_user_id nullable, external_email nullable,
provisioned_at TIMESTAMP nullable,
provision_status DEFAULT 'pending', -- VARCHAR(255), not an enum
plan_id FK product_plans nullable ON DELETE SET NULL,
plan_price_id FK product_plan_prices nullable ON DELETE SET NULL,
label VARCHAR(100) nullable,
-- label tells multiple subscriptions of the same product apart per
-- customer ("Main website", "Blog"); the (customer_id, product_id)
-- index is deliberately non-unique to allow that.
billing_entity_id FK nullable,
stripe_subscription_id VARCHAR(100) nullable,
stripe_price_id VARCHAR(100) nullable,
plan VARCHAR(100) nullable, price_monthly DECIMAL(10,2) nullable,
interval_count TINYINT UNSIGNED DEFAULT 1,
interval_unit ENUM(day|week|month|year|one_time) DEFAULT 'month',
status ENUM(active|trial|suspended|cancelled|pending),
-- 'pending' (2026_05_29) = portal self-service signup awaiting staff
-- approval from the internal Provisioning page.
trial_ends_at nullable, started_at nullable,
next_billing_date DATE nullable,
auto_invoice BOOLEAN DEFAULT false,
auto_invoice_entity_id FK billing_entities nullable (SET NULL),
last_invoiced_at DATE nullable,
-- Auto-invoicing (2026_05_30): the daily invoices:generate-subscriptions
-- sweep drafts an invoice on next_billing_date and rolls the date
-- forward; NULL entity falls back to the first active billing entity;
-- last_invoiced_at is the audit breadcrumb the command writes.
discount_pct DECIMAL(5,2) nullable,
discount_expires_at DATE nullable,
cancels_at DATE nullable,
cancelled_at nullable,
oauth_client_id BIGINT nullable,
wp_user_id BIGINT nullable,
config JSON nullable,
-- Suspension audit trail (Webhooks + Auto-Suspension sprint).
-- suspended_by/reinstated_by NULL = action taken by the system
-- (auto-suspend sweep / auto-reinstate) rather than a staff user.
suspension_reason ENUM(non_payment|manual|trial_ended|fraud|other) nullable,
suspended_at TIMESTAMP nullable,
suspended_by BIGINT FK users nullable (SET NULL),
reinstatement_reason TEXT nullable,
reinstated_at TIMESTAMP nullable,
reinstated_by BIGINT FK users nullable (SET NULL),
created_at, updated_at
INDEXES: (customer_id, product_id), status, next_billing_date,
(status, auto_invoice, next_billing_date) -- auto-invoice sweep,
(provision_status, external_user_id) -- provisioning sweep/reconcile

## billing_entities
id, name, legal_name, company_number, vat_number,
default_vat_rate DECIMAL(5,2) DEFAULT 20.00,
vat_registered BOOLEAN DEFAULT true
  -- Proposals sprint: when false, every document from this entity
  -- renders without a VAT line. Backfill turned the flag off for
  -- any pre-existing entity with a NULL vat_number.
address JSON, bank_name,
sort_code TEXT (encrypted), account_number TEXT (encrypted),
account_name TEXT (encrypted),
iban TEXT nullable (encrypted), bic TEXT nullable (encrypted),
  -- 2026_06_09: international bank details (IBAN + BIC/SWIFT) per entity,
  -- alongside the UK fields; encrypted at rest like the other bank columns.
  -- Nullable — entities without them render no IBAN/BIC on invoices.
logo_path nullable, postmark_sender_email,
postmark_sender_name, postmark_domain nullable,
qbo_realm_id nullable,
qbo_access_token TEXT nullable (encrypted),
qbo_refresh_token TEXT nullable (encrypted),
qbo_token_expires_at nullable,
is_active BOOLEAN DEFAULT true, created_at, updated_at

## invoices
id, number VARCHAR(20) UNIQUE, customer_id FK,
billing_entity_id FK, type ENUM(subscription|service),
status ENUM(draft|sent|partially_paid|paid|overdue|void),
  -- partially_paid added in the 6-fixes sprint; markPaid()
  -- accumulates amount_paid and branches status on whether
  -- the running total covers invoice.total.
is_recurring BOOLEAN DEFAULT false,
recurring_interval_count TINYINT UNSIGNED nullable,
recurring_interval_unit ENUM(week|month|year) nullable,
recurring_next_date DATE nullable, recurring_ends_at DATE nullable,
parent_invoice_id FK invoices nullable (SET NULL),
  -- Recurring templates: invoices:generate-recurring clones the
  -- template into a draft child on each recurring_next_date;
  -- parent_invoice_id tracks lineage from the child side.
subtotal DECIMAL(10,2), vat_rate DECIMAL(5,2),
vat_amount DECIMAL(10,2), total DECIMAL(10,2),
amount_paid DECIMAL(10,2) DEFAULT 0,
issue_date DATE, due_date DATE, paid_at nullable,
payment_method ENUM(bank_transfer|card|direct_debit|other) nullable,
payment_reference VARCHAR(255) nullable,
notes TEXT nullable, pdf_path VARCHAR(500) nullable,
sent_at nullable, qbo_invoice_id VARCHAR(100) nullable,
stripe_payment_intent_id VARCHAR(100) nullable,
stripe_checkout_session_id VARCHAR(100) nullable,
stripe_payment_link VARCHAR(500) nullable,
  -- Stripe Checkout: hosted payment URL (stripe_payment_link) plus the
  -- session/intent ids the webhook reconciles against. Settled by
  -- Webhooks\StripeWebhookController → StripeService::markInvoicePaid().
paid_via ENUM(manual|stripe|bank) nullable,
  -- Channel that cleared the balance. Backfilled via the reconciling
  -- migration 2026_06_02_130000 (columns pre-existed the migration).
reminder_count INT UNSIGNED DEFAULT 0,
last_reminder_sent_at nullable,
next_reminder_at nullable,
reminders_paused BOOLEAN DEFAULT FALSE,
created_by BIGINT FK users nullable (2026_07_06),
  -- NULL = system-created (Plans-widget webhook provisioning —
  -- PLANS-WIDGET-DESIGN.md §4). Same convention as payments.created_by
  -- and activity_log.user_id. All staff paths still record a real user.
created_at, updated_at
INDEX (next_reminder_at, reminders_paused, status)
INDEX (is_recurring, recurring_next_date) invoices_recurring_sweep_idx

## invoice_lines
id, invoice_id FK,
product_id FK products nullable,
plan_id FK product_plans nullable,
description VARCHAR(500),
note VARCHAR(500) nullable, quantity DECIMAL(10,3),
unit_price DECIMAL(10,2),
amount DECIMAL(10,2)
  -- POST-discount value. computeLineDiscount() is the only writer.
discount_type ENUM(percentage|fixed) nullable,
discount_value DECIMAL(10,2) nullable DEFAULT 0,
discount_amount DECIMAL(10,2) nullable DEFAULT 0
  -- Cooked discount £ — stored for audit; never recomputed on read.
sort_order INT DEFAULT 0, created_at, updated_at

## reminder_templates
id, name VARCHAR(100),
tier ENUM(due_soon|due_today|first_reminder|second_reminder|
  final_notice) UNIQUE,
subject VARCHAR(255), body LONGTEXT,
tone ENUM(friendly|firm|urgent|final),
is_active BOOLEAN DEFAULT true,
variables_used JSON nullable
  -- Which {{placeholders}} the body references — a management-UI
  -- usage hint only; not enforced at write time.
created_at, updated_at
-- One template per escalation tier: when an invoice reminder fires
-- (see reminder_count / next_reminder_at on invoices) the renderer
-- looks the row up by tier. ENUM + UNIQUE make one-per-tier a DB
-- constraint, not a convention.

## stripe_customers   (Billing P1)
id, customer_id FK customers (cascade) UNIQUE,
stripe_customer_id VARCHAR(100) UNIQUE,
created_at, updated_at
-- The Customer ↔ Stripe-Customer mapping for the single GBP Stripe
-- account. Its own table (not a bare customers column) so a future
-- per-billing-entity / per-Stripe-account split is ADDITIVE: add
-- billing_entity_id + relax UNIQUE to (customer_id, billing_entity_id).

## payment_methods   (Billing P1)
id, customer_id FK customers (cascade),
stripe_customer_id VARCHAR(100),
stripe_payment_method_id VARCHAR(100) UNIQUE,
brand VARCHAR(40) nullable, last4 VARCHAR(4) nullable,
exp_month TINYINT UNSIGNED nullable, exp_year SMALLINT UNSIGNED nullable,
is_default BOOLEAN DEFAULT false,
status ENUM(active|removed) DEFAULT active,
created_at, updated_at
INDEX (customer_id, status)
-- Vaulted cards. SAFE metadata only — NEVER the PAN/CVC/secret; the
-- card lives in Stripe (stripe_payment_method_id). Future per-entity
-- split adds billing_entity_id.

## payments   (Billing P1 — payments ledger)
id, invoice_id FK invoices (cascade),
customer_id FK customers (cascade),
amount DECIMAL(10,2), currency CHAR(3) DEFAULT 'gbp',
rail ENUM(stripe|manual|bank|other) DEFAULT stripe,
stripe_payment_intent_id VARCHAR(100) nullable UNIQUE,   -- P2: unique for upsert-by-PI (NULLs allowed for manual/bank)
status ENUM(pending|succeeded|failed|requires_action) DEFAULT succeeded,   -- requires_action added P2 (SCA)
attempted_at TIMESTAMP nullable,
failure_reason VARCHAR(500) nullable,
created_by FK users nullable (SET NULL),
created_at, updated_at
INDEX (invoice_id), (customer_id, status)
-- One row per settlement attempt. On-session checkout + manual mark-paid (P1)
-- AND off-session collection (P2). The inline command success and the async
-- webhook converge on ONE row via the UNIQUE(stripe_payment_intent_id) upsert.

## maavelus_statements
id, period_start DATE UNIQUE, period_end DATE,
total_fees DECIMAL(10,2) DEFAULT 0,
total_orders INT UNSIGNED nullable,
status ENUM(draft|confirmed) DEFAULT draft,
notes TEXT nullable, pdf_path VARCHAR(500) nullable,
data_source ENUM(manual|api) DEFAULT manual,
commissions_generated BOOLEAN DEFAULT FALSE,
confirmed_by BIGINT FK users nullable,
confirmed_at nullable,
created_by BIGINT FK users, created_at, updated_at

## maavelus_statement_lines
id, statement_id FK maavelus_statements ON DELETE CASCADE,
customer_id FK customers, total_fees DECIMAL(10,2),
order_count INT UNSIGNED nullable, created_at, updated_at

## referrers
id, user_id FK users,
referral_code VARCHAR(16) nullable UNIQUE
  -- 8-char Crockford base32 (no I/L/O/U). The referrer's one universal
  -- /r/{code} link. Backfilled for existing rows; minted on create.
payment_details JSON nullable (encrypted:array, LONGTEXT),
is_active BOOLEAN DEFAULT true, created_at, updated_at

## customer_referrals
-- This IS the spec's "referrals" record — extended in place, NOT a new
-- table. One immutable attribution per customer (UNIQUE customer_id);
-- last-touch is resolved before insert by AttributionService.
id, customer_id BIGINT UNIQUE FK,
referrer_id FK,
lead_id FK leads nullable (set null),
click_id FK referral_clicks nullable (set null),
product VARCHAR(30) nullable,            -- ProductKey value
source VARCHAR(20) NOT NULL DEFAULT 'manual'  -- AttributionSource enum
campaign VARCHAR(100) nullable,
attributed_at, converted_at nullable,
created_at, updated_at nullable          -- rows immutable; $timestamps=false

## referral_clicks
-- Immutable click events on /r/{code}. Source for last-touch attribution
-- within the 60-day window. One row per VALID-code hit (invalid codes
-- are not logged).
id, referrer_id FK referrers (cascade),
referral_code VARCHAR(16),               -- denormalised for fast lookup
product VARCHAR(30) nullable,            -- the `p` param (ProductKey)
campaign VARCHAR(100) nullable,          -- the `c` param (length-capped)
utm_source / utm_medium / utm_campaign VARCHAR(255) nullable,
landing_url VARCHAR(2048) nullable,
ip_address VARCHAR(45) nullable,
user_agent VARCHAR(512) nullable,
created_at (useCurrent)                  -- no updated_at
INDEX (referrer_id), (referral_code), (created_at)

## commission_rules
id, referrer_id FK nullable,   -- NULL = product-wide default; referrer-specific wins
product_id FK,
type ENUM(one_off_pct|recurring_tiered|hybrid),
config JSON,
  -- Commission-engine config keys (shared CommissionService::calculate):
  --   flat_amount        : fixed payout; TAKES PRECEDENCE over any percentage
  --   percentage         : one_off_pct → % of gross
  --   recurring_percentage : hybrid → % of recurring gross
  --   recurring_months   : recurring duration cap (N). null/0/absent = lifetime
  -- recurring_tiered is DEFERRED (Sprint 2) — calculate() stubs it to 0.
valid_from DATE, valid_until DATE nullable,   -- effective-dating for resolution
is_active BOOLEAN DEFAULT true, created_at, updated_at

## commission_ledger
id, referrer_id FK, customer_id FK,
invoice_id FK nullable, rule_id FK, product_id FK,
trigger_type ENUM(onboarding|invoice_paid|monthly_recurring),
  -- onboarding = one-off (first sale, once per customer×product);
  -- invoice_paid = recurring (subscription-invoice) accrual;
  -- monthly_recurring = the Maavelus statement flow.
gross_amount DECIMAL(10,2), commission_amount DECIMAL(10,2),
status ENUM(pending|approved|paid|voided),
period_start DATE nullable, period_end DATE nullable,
approved_by BIGINT FK users nullable, approved_at nullable,
paid_at nullable, voided_reason VARCHAR(500) nullable,
created_at, updated_at
-- UNIQUE (invoice_id, referrer_id, product_id)
--   commission_ledger_invoice_referrer_product_unique (Commission engine Sprint 1)
--   Idempotency backstop: never credit the same invoice×referrer×product twice
--   (webhook retries + multi-path settle). invoice_id NULLable → MySQL treats
--   NULLs as distinct, so Maavelus null-invoice rows never collide.
-- Engine writes are gated by config('referrals.commission_excluded_slugs')
--   (Maavelus products are skipped — they accrue via their statement flow).

## expenses (6-fixes sprint)
id,
category ENUM(referral_commission|software|hosting|travel|
  office|marketing|advertising|equipment|other) DEFAULT 'other',
description VARCHAR(255),
supplier_name VARCHAR(255) nullable
  -- Legacy / ad-hoc free-text payee. Renamed from `supplier` in the
  -- Suppliers sprint. Fallback when supplier_id is null.
supplier_id FK suppliers nullable (SET NULL)
  -- Links to the supplier register. When set, the supplier's name is
  -- the display payee; supplier_name is the fallback.
qbo_bill_id VARCHAR(100) nullable UNIQUE
  -- QuickBooks Online bill id. Populated by a future QBO sync.
amount DECIMAL(10,2)
  -- Net amount before VAT.
vat_rate DECIMAL(5,2) DEFAULT 0,
vat_amount DECIMAL(10,2) DEFAULT 0,
total DECIMAL(10,2)
  -- amount + vat_amount. Stored, not derived; recomputed in
  -- Expense::saving() so per-category SUM() reports stay cheap.
expense_date DATE,
status ENUM(pending|approved|paid) DEFAULT 'pending',
is_reimbursable BOOLEAN DEFAULT false,
receipt_path VARCHAR(500) nullable
  -- Lives on the private disk via FileUploadService.
receipt_original_name VARCHAR(255) nullable,
project_id FK projects nullable (SET NULL),
customer_id FK customers nullable (SET NULL),
commission_ledger_id FK commission_ledger nullable (SET NULL)
  -- Auto-set by ExpenseController::createFromCommission when a
  -- commission row is marked paid. Idempotency anchor — the
  -- helper bails out if a row already exists for this ledger id.
notes TEXT nullable,
created_by BIGINT FK users (RESTRICT),
approved_by BIGINT FK users nullable (SET NULL),
paid_at TIMESTAMP nullable,
created_at, updated_at
INDEX (category, status, expense_date) expenses_filter_idx
INDEX (commission_ledger_id) expenses_commission_idx

## suppliers (Suppliers sprint)
id, name VARCHAR(255),
type ENUM(software|hosting|marketing|domain_registrar|finance|
  utilities|professional_services|other) DEFAULT 'other',
contact_name VARCHAR(255) nullable,
email VARCHAR(255) nullable,
phone VARCHAR(50) nullable,
website VARCHAR(500) nullable,
address TEXT nullable,
account_number VARCHAR(100) nullable
  -- Our account reference with this supplier.
payment_terms VARCHAR(100) nullable
  -- e.g. "Net 30", "Monthly direct debit".
default_expense_category VARCHAR(50) nullable
  -- Mirrors the expenses.category enum; auto-fills the expense form.
default_vat_rate DECIMAL(5,2) DEFAULT 20.00,
notes TEXT nullable,
is_active BOOLEAN DEFAULT true,
qbo_vendor_id VARCHAR(100) nullable UNIQUE,
qbo_sync_status ENUM(not_synced|synced|error|excluded)
  DEFAULT 'not_synced',
qbo_synced_at TIMESTAMP nullable,
qbo_sync_error TEXT nullable
  -- QBO columns are populated by a future QuickBooks sync sprint.
created_by BIGINT FK users (RESTRICT),
created_at, updated_at
INDEX (type, is_active) suppliers_type_active_idx
INDEX (name) suppliers_name_idx

## product_suppliers (Suppliers sprint)
product_id FK products CASCADE,
supplier_id FK suppliers CASCADE,
cost_per_unit DECIMAL(10,2) DEFAULT 0,
billing_interval ENUM(monthly|quarterly|annually|one_time)
  DEFAULT 'monthly',
notes TEXT nullable,
sort_order INT DEFAULT 0,
created_at, updated_at
PRIMARY KEY (product_id, supplier_id)
INDEX (supplier_id) product_suppliers_supplier_idx
  -- Cost lines behind a product, for margin reporting. Pivot model
  -- App\Models\ProductSupplier. Monthly cost is amortised
  -- (annually/12, quarterly/3); one_time excluded from margin.

## proposals (Proposals sprint)
id,
customer_id FK customers (RESTRICT)
  -- Losing a customer mid-flight would orphan a legally-binding
  -- accepted document; restrict-on-delete forces the cleanup
  -- to happen explicitly.
billing_entity_id FK billing_entities nullable (SET NULL),
project_id FK projects nullable (SET NULL),
contract_id FK contracts nullable (SET NULL)
  -- Set on convertToContract — the audit link back to the
  -- spawned contract row.
reference VARCHAR(20) UNIQUE
  -- PROP-2026-0001 format. Generated by Proposal::generateNextReference
  -- inside the creating transaction so two concurrent stores
  -- can't collide.
title VARCHAR(255), description TEXT nullable, terms TEXT nullable,
status ENUM(draft|sent|accepted|rejected|expired) DEFAULT 'draft',
subtotal DECIMAL(10,2), discount_amount DECIMAL(10,2) DEFAULT 0,
vat_rate DECIMAL(5,2) DEFAULT 20.00, vat_amount DECIMAL(10,2) DEFAULT 0,
total DECIMAL(10,2),
valid_until DATE nullable,
sent_at TIMESTAMP nullable,
acceptance_token VARCHAR(64) UNIQUE nullable
  -- Opaque sha256 token. Nulled on accept — single-use.
acceptance_token_expires_at TIMESTAMP nullable,
accepted_at TIMESTAMP nullable,
accepted_by_name VARCHAR(255) nullable,
accepted_ip VARCHAR(45) nullable,
accepted_user_agent TEXT nullable,
rejected_at TIMESTAMP nullable, rejection_reason TEXT nullable,
pdf_path VARCHAR(500) nullable
  -- Unsigned PDF on the private disk; written at send-time.
accepted_pdf_path VARCHAR(500) nullable
  -- Second PDF with the acceptance stamp; what we ship into
  -- Contracts on conversion.
notes TEXT nullable, created_by FK users (RESTRICT),
created_at, updated_at
INDEX (customer_id, status)
-- reference + acceptance_token indexes provided by their UNIQUE.

## proposal_lines (Proposals sprint)
id, proposal_id FK proposals (CASCADE),
description VARCHAR(500), note TEXT nullable,
quantity DECIMAL(8,2) DEFAULT 1,
unit_price DECIMAL(10,2) DEFAULT 0,
amount DECIMAL(10,2) DEFAULT 0
  -- POST-discount net. The same compute helper as invoice_lines.
discount_type ENUM(percentage|fixed) nullable,
discount_value DECIMAL(10,2) DEFAULT 0,
discount_amount DECIMAL(10,2) DEFAULT 0
  -- Cooked figure, stored for audit.
product_id FK products nullable (SET NULL),
plan_id FK product_plans nullable (SET NULL),
sort_order INT DEFAULT 0, created_at, updated_at
INDEX (proposal_id, sort_order)

## payment_schedules (Proposals sprint)
id, name VARCHAR(255),
proposal_id FK proposals nullable (SET NULL),
project_id FK projects nullable (SET NULL),
customer_id FK customers (RESTRICT),
billing_entity_id FK billing_entities nullable (SET NULL),
total DECIMAL(10,2), notes TEXT nullable,
created_by FK users (RESTRICT),
created_at, updated_at

## payment_schedule_items (Proposals sprint)
id, schedule_id FK payment_schedules (CASCADE),
label VARCHAR(255),
percentage DECIMAL(5,2) nullable
  -- What the operator typed; amount is the cooked £ figure so
  -- editing the schedule total can re-derive amounts without
  -- losing intent.
amount DECIMAL(10,2),
trigger_type ENUM(immediate|on_date|on_milestone|manual) DEFAULT 'manual',
trigger_date DATE nullable,
milestone_id FK milestones nullable (SET NULL),
invoice_id FK invoices nullable (SET NULL)
  -- Set when the item is spawned into an invoice. MilestoneController
  -- and the public acceptance flow both write this via
  -- ProposalAcceptanceController::generateScheduleInvoice.
status ENUM(pending|invoiced|paid) DEFAULT 'pending',
sort_order INT DEFAULT 0, created_at, updated_at
INDEX (schedule_id, sort_order)
INDEX (milestone_id, status) -- milestone-completion hook
INDEX (trigger_type, trigger_date, status) -- date-cron (Sprint 2)

## domains
id, customer_id FK nullable, domain VARCHAR(255) UNIQUE,
registered_at DATE nullable,
status ENUM(active|expiring_soon|expired|parked|transferred)
  DEFAULT 'active'
  -- Domains & DNS sprint (2026_05_30): computed health flag so the index
  -- page and the artisan checker don't recompute on every read; the
  -- check command writes it back whenever it runs.
cloudflare_zone_id VARCHAR(100) nullable,
registrar VARCHAR(100) nullable,
is_in_cloudflare BOOLEAN DEFAULT false,
is_proxied BOOLEAN DEFAULT false,
expiry_date DATE nullable, ssl_expiry_date DATE nullable,
ssl_status ENUM(active|expiring|expired|none) DEFAULT 'none'
  -- Computed SSL health flag; same write-back as status.
nameservers JSON nullable,
auto_renew BOOLEAN DEFAULT false,
product_plan_id FK product_plans nullable (SET NULL)
  -- DERIVED cached link to the matched is_domain plan (set on save +
  -- by the renewal command from the TLD). NOT a CustomerProduct —
  -- the subscription cron never bills domains.
tld VARCHAR(20) nullable
  -- User-facing renewal control: matched to an active is_domain plan's
  -- tld to resolve the renewal price + term. Null = no auto renewal.
renewal_invoiced_for DATE nullable
  -- The expiry_date the last renewal invoice covered (idempotency).
  -- invoices:generate-domain-renewals bills only when this differs
  -- from the current expiry_date → once per expiry cycle.
hosting_provider VARCHAR(100) nullable,
hosting_renewal_date DATE nullable,
hosting_notes TEXT nullable,
notes TEXT nullable
  -- Operator notes for the domain management surface; kept separate from
  -- the legacy hosting_notes so the customer-page hosting card doesn't
  -- show generic domain notes alongside hosting-specific ones.
last_synced_at nullable, created_at, updated_at
-- INDEX (status) domains_status_idx

## websites (Websites sprint — cPanel/WHM/PageSpeed)
id,
customer_id FK customers (RESTRICT),
name VARCHAR(255), url VARCHAR(500),
customer_product_id FK customer_products nullable (SET NULL)
  -- LEGACY hosting link (Stage 1a: hosting moved onto the website
  -- below). Kept inert for the WebhookDispatcher cascade; re-routed
  -- in Stage 1b.
-- Hosting (Stage 1a) — carried directly on the website, sourced from
-- the catalog (NO CustomerProduct). Billing-anchor columns mirror
-- customer_products.{auto_invoice,next_billing_date,last_invoiced_at};
-- inert until Stage 1b wires the per-website hosting invoice sweep.
plan_id FK product_plans nullable (SET NULL)
  -- the active is_hosting plan this site is hosted on
plan_price_id FK product_plan_prices nullable (SET NULL)
  -- the chosen active price tier of plan_id
hosting_status ENUM(none|active|suspended) DEFAULT none,
hosting_started_at TIMESTAMP nullable,
hosting_auto_invoice BOOLEAN DEFAULT false,
hosting_next_billing_date DATE nullable,
hosting_last_invoiced_at DATE nullable,
domain_id FK domains nullable (SET NULL),
project_id FK projects nullable (SET NULL),
-- cPanel access (per site)
cpanel_username VARCHAR(100) nullable,
cpanel_token TEXT nullable (encrypted)
  -- Laravel encrypted cast; never stored plaintext.
cpanel_server VARCHAR(255) nullable DEFAULT '040hosting.eu',
whm_managed BOOLEAN DEFAULT false
  -- true = WHM may auto-suspend this account.
-- Hosting usage (cPanel UAPI)
disk_used_mb INT UNSIGNED nullable, disk_quota_mb INT UNSIGNED nullable,
email_accounts_count SMALLINT UNSIGNED nullable,
email_accounts_quota SMALLINT UNSIGNED nullable,
bandwidth_used_mb INT UNSIGNED nullable,
bandwidth_quota_mb INT UNSIGNED nullable,
usage_checked_at TIMESTAMP nullable,
-- WordPress (MainWP)
mainwp_site_id INT UNSIGNED nullable,
wp_version VARCHAR(20) nullable,
wp_core_update_available BOOLEAN DEFAULT 0,   -- core upgrade pending (from wp_upgrades.new)
wp_latest_version VARCHAR(20) nullable,        -- target core version when an upgrade is pending
php_version VARCHAR(20) nullable,
plugins_total SMALLINT UNSIGNED DEFAULT 0,
plugins_outdated SMALLINT UNSIGNED DEFAULT 0,
plugin_updates_detail JSON nullable,   -- [{name,slug,current_version,new_version}] per outdated plugin
themes_outdated SMALLINT UNSIGNED DEFAULT 0,
theme_updates_detail JSON nullable,    -- [{name,slug,current_version,new_version}] per outdated theme
last_backup_at TIMESTAMP nullable,
-- PageSpeed (Google PSI)
pagespeed_mobile TINYINT UNSIGNED nullable,
pagespeed_desktop TINYINT UNSIGNED nullable,
pagespeed_lcp DECIMAL(5,2) nullable, pagespeed_cls DECIMAL(5,3) nullable,
pagespeed_fcp DECIMAL(5,2) nullable, pagespeed_tbt INT UNSIGNED nullable,
pagespeed_data JSON nullable, pagespeed_checked_at TIMESTAMP nullable,
-- Analytics (GA4, future)
ga4_property_id VARCHAR(50) nullable,
monthly_visitors INT UNSIGNED nullable,
analytics_updated_at TIMESTAMP nullable,
status ENUM(active|suspended|migrating|cancelled) DEFAULT active,
notes TEXT nullable,
created_by FK users (RESTRICT), created_at, updated_at
INDEX (customer_id, status), (cpanel_username),
  (pagespeed_checked_at), (usage_checked_at)

## contracts
id, customer_id FK, created_by FK users,
type ENUM(service_agreement|sow|retainer|nda|other),
title VARCHAR(255), description TEXT nullable
  -- Operator's plain-English summary (customer-facing surface);
  -- `notes` below stays the internal-only field.
value DECIMAL(10,2) nullable,
status ENUM(draft|sent|signed|countersigned|expired|void),
sent_at nullable, signed_at nullable,
signed_ip VARCHAR(45) nullable,
countersigned_at nullable,
start_date DATE nullable, end_date DATE nullable,
pdf_path VARCHAR(500) nullable,
file_original_name VARCHAR(255) nullable
  -- Upload's friendly filename, used only for the download header;
  -- storage paths stay uuid-generated by FileUploadService.
notes TEXT nullable, created_at, updated_at

## support_tickets
id,
customer_id FK nullable (cascade)
  -- nullable since 2026-06-03: public/guest tickets have no customer.
guest_name VARCHAR(255) nullable,
guest_email VARCHAR(255) nullable (indexed),
guest_phone VARCHAR(50) nullable
  -- submitter details for guest tickets (public /support form). Populated
  -- only when customer_id is null. Staff/portal tickets leave these null.
contact_id FK nullable,
product_id FK nullable, subject VARCHAR(500),
status ENUM(open|in_progress|awaiting_customer|resolved|closed),
priority ENUM(low|medium|high|urgent),
assigned_to BIGINT FK users nullable,
sentiment_score DECIMAL(3,2) nullable,
sla_breach_at nullable
  -- = created_at + first-response hours (config/support.php, per priority).
  -- Treated as the first-RESPONSE deadline. Breach is COMPUTED ON READ
  -- (SupportTicket::slaState → App\Enums\SlaState met|due|breached); no
  -- stored breach flag, no sweep.
first_responded_at nullable (SLA sprint)
  -- Stamped once, on the FIRST staff non-internal reply (SupportSlaService).
reopened_at nullable, reopen_count INT default 0 (SLA sprint)
  -- Stamped/incremented on a resolved|closed → open transition (reply,
  -- status change, or inbound-email reply), via SupportSlaService.
resolved_at nullable
  -- Resolution time is MEASURED, not committed: avg raw (resolved_at −
  -- created_at), no pause, surfaced in Analytics.
closed_at nullable, created_at, updated_at

## support_messages
id, ticket_id FK,
sender_type ENUM(customer|staff|ai),
sender_id BIGINT nullable,
body TEXT, is_internal_note BOOLEAN DEFAULT false,
ai_confidence DECIMAL(3,2) nullable,
ai_model VARCHAR(100) nullable,
message_id VARCHAR(255) nullable
  -- Postmark Message-ID, for inbound In-Reply-To threading (Email sprint).
source VARCHAR(50) nullable DEFAULT 'web'
  -- web | email | api
created_at, updated_at
INDEX (message_id)

## support_knowledge_base
id, title VARCHAR(255), slug VARCHAR(255) UNIQUE,
content LONGTEXT (markdown), category VARCHAR(100),
is_public BOOLEAN DEFAULT true,
is_published BOOLEAN DEFAULT true,
sort_order INT DEFAULT 0, views INT DEFAULT 0,
author_id FK users, created_at, updated_at
-- Reworked for Help & docs. is_public gates customer-portal
-- visibility; is_published gates everywhere. Soft-delete via
-- is_published=false.

## leads (Leads sprint)
id,
first_name VARCHAR(100), last_name VARCHAR(100) nullable,
email VARCHAR(255) nullable, phone VARCHAR(50) nullable,
company VARCHAR(255) nullable, job_title VARCHAR(255) nullable,
status ENUM(new|contacted|qualified|proposal|negotiation
  |won|lost|unresponsive) DEFAULT 'new',
source ENUM(manual|landing_page|facebook|google|referral
  |email|phone|event|word_of_mouth|other|import) DEFAULT 'manual',
  -- 'import' = CSV lead import (2026_07_01 widen); convert() coerces it
  -- to acquisition_channel 'other' via the channelMap.
source_detail VARCHAR(255) nullable,
referrer_id FK referrers nullable SET NULL
  -- Captured at public-form submission (?ref / wd_ref cookie); fed to
  -- AttributionService at convert() to create the CustomerReferral.
referral_code VARCHAR(16) nullable,
-- Deal registration (Deal-Registration sprint). referral_status is the
-- registration lifecycle, SEPARATE from `status` above. NULL = not a
-- deal-registration (manual + cookie-attributed leads are unaffected).
referral_status ENUM(pending_review|approved|rejected|expired) nullable,
registered_at TIMESTAMP nullable,        -- set at submission
protected_until TIMESTAMP nullable,      -- = approval time + 90d (set on approval)
reviewed_by FK users nullable SET NULL,  -- staff approver/rejecter
reviewed_at TIMESTAMP nullable,
review_notes TEXT nullable,              -- holds the rejection reason
referral_consent_at TIMESTAMP nullable,  -- referrer's GDPR attestation
-- INDEX (referral_status)              leads_referral_status_idx
-- INDEX (protected_until)             leads_protected_until_idx
assigned_to FK users nullable SET NULL,
estimated_value DECIMAL(10,2) nullable,
notes TEXT nullable,
customer_id FK customers nullable SET NULL
  -- Stamped on conversion; the index/list filters whereNull on
  -- this column so converted leads vanish from the pipeline.
converted_at TIMESTAMP nullable,
lost_reason TEXT nullable,
form_submission_id UNSIGNED BIGINT nullable
  -- No FK yet; the forms table arrives in Sprint 3.
created_by FK users RESTRICT,
created_at, updated_at
-- INDEX (status, assigned_to)         leads_kanban_idx
-- INDEX (source, created_at)          leads_funnel_idx
-- INDEX (customer_id)                 leads_converted_idx
-- INDEX (assigned_to, status)         leads_mywork_idx
-- Leads live in their own table so a half-qualified prospect
-- never leaks into /customers. Conversion via
-- LeadController::convert() creates Customer + primary Contact,
-- re-targets tasks/notes, then stamps customer_id + converted_at
-- on the lead itself for audit / lead_origin chip on the
-- customer detail page.

## notes
id, customer_id FK, created_by FK users,
lead_id FK leads nullable SET NULL (Leads sprint)
  -- A note can hang off a customer, a task, or a lead. On lead
  -- conversion LeadController::convert() re-targets the lead's
  -- notes at the new customer (lead_id = null, customer_id set).
task_id FK tasks nullable SET NULL
  -- Scopes a note to an activity thread on the task detail page.
  -- SET NULL keeps the note for the audit trail after the parent
  -- task is gone; the customer link is the authoritative anchor.
type ENUM(internal|call|meeting|email),
body TEXT, is_pinned BOOLEAN DEFAULT false, created_at, updated_at

## tasks
id, customer_id FK nullable,
project_id FK projects nullable (PM Sprint 1),
milestone_id FK milestones nullable (PM Sprint 1),
lead_id FK leads nullable SET NULL (Leads sprint)
  -- Column landed empty in PM Sprint 1. The FK was added by
  -- the leads migration once the referenced table existed.
ticket_id FK support_tickets nullable SET NULL
  -- Link back to the spawning support ticket. TicketIntakeService
  -- stamps it on new triage tasks; older triage tasks keep null
  -- and fall back to the ticket ref in the title text.
contact_id FK nullable,
parent_task_id FK tasks nullable,
assigned_to FK users, created_by FK users,
title VARCHAR(500),
type ENUM(task|call|email|meeting|note) DEFAULT 'task',
description TEXT nullable,
priority ENUM(low|medium|high) DEFAULT 'medium',
status ENUM(todo|in_progress|in_review|blocked|complete|cancelled)
  DEFAULT 'todo' (PM Sprint 1: widened from {open,complete}),
due_date DATE nullable (legacy — kept for safety),
due_at TIMESTAMP nullable (canonical schedule),
start_at TIMESTAMP nullable (Calendar sprint),
end_at TIMESTAMP nullable,
location VARCHAR(255) nullable,
is_all_day BOOLEAN DEFAULT true
  -- true = due_at is a date-only deadline (the legacy shape);
  -- false = timed event, start_at/end_at carry the real slot.
google_event_id VARCHAR(255) nullable
  -- Google Calendar event id once the task is mirrored there.
completed_at TIMESTAMP nullable,
outcome TEXT nullable,
duration_minutes UNSIGNED INT nullable,
estimated_hours DECIMAL(6,2) nullable (PM Sprint 1),
sort_order UNSIGNED INT DEFAULT 0 (PM Sprint 1, kanban order),
blocked_reason TEXT nullable (PM Sprint 1),
is_pinned BOOLEAN DEFAULT false,
created_at, updated_at
-- Repurposed from simple tasks into CRM activity model,
-- then extended for the project-management kanban.
-- INDEX (customer_id, is_pinned, due_at) for the timeline query.
-- INDEX (project_id, milestone_id, sort_order) tasks_pm_board_idx
-- INDEX (project_id, status) tasks_pm_status_idx
-- INDEX (assigned_to, status) tasks_mywork_idx
-- INDEX (start_at) tasks_start_at_index (Calendar sprint)
-- INDEX (google_event_id) tasks_google_event_id_index
-- Migration 2026_05_30_070004 backfilled the old enum:
--   open → todo, complete → complete. No row was lost.

## task_attachments
id, task_id FK tasks (CASCADE),
filename VARCHAR(255)
  -- Original upload name, display only — the stored name comes from
  -- FileUploadService; files live on the private disk, never public/.
path VARCHAR(500), mime_type VARCHAR(100),
size_bytes UNSIGNED INT,
uploaded_by FK users (RESTRICT)
  -- Restrict so a user with attachments can't be hard-deleted out
  -- from under the audit trail.
created_at, updated_at
INDEX (task_id)
-- Metadata table only — the bytes themselves are on the private disk.

## projects (PM Sprint 1)
id, customer_id FK nullable
  -- Nullable so we can model internal projects with no customer.
title VARCHAR(255), description TEXT nullable,
status ENUM(planning|active|on_hold|completed|cancelled)
  DEFAULT 'planning',
priority ENUM(low|medium|high|urgent) DEFAULT 'medium',
colour VARCHAR(7) DEFAULT '#3B82F6'
  -- Used by kanban headers, MyWork strips, project cards.
start_date DATE nullable, due_date DATE nullable,
budget DECIMAL(10,2) nullable,
hourly_rate DECIMAL(8,2) nullable
  -- Default billing rate used for time entries on this project.
project_lead BIGINT FK users nullable,
created_by BIGINT FK users (RESTRICT),
completed_at TIMESTAMP nullable,
archived_at TIMESTAMP nullable
  -- Soft-archive marker. Hidden from list/kanban but tasks +
  -- time entries remain queryable for historical billing.
created_at, updated_at
INDEX (customer_id, status)
INDEX (due_date, status)
INDEX (status, archived_at)

## milestones (PM Sprint 1)
id, project_id FK projects (CASCADE),
title VARCHAR(255), description TEXT nullable,
due_date DATE nullable,
status ENUM(pending|in_progress|completed) DEFAULT 'pending',
sort_order UNSIGNED INT DEFAULT 0,
completed_at TIMESTAMP nullable,
created_at, updated_at
INDEX (project_id, sort_order)
INDEX (project_id, status)
-- Cascade-delete fine: a milestone is meaningless outside its
-- project. Tasks lose their milestone via SET NULL on the FK,
-- so no task is lost when a milestone is deleted.

## project_members (PM Sprint 1)
project_id FK projects (CASCADE),
user_id FK users (CASCADE),
role ENUM(lead|member|viewer) DEFAULT 'member',
joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
PRIMARY KEY (project_id, user_id)
INDEX (user_id)
-- Composite-PK pivot. No auto-increment id. No timestamps
-- beyond joined_at (the row's whole reason to exist).

## time_entries (PM Sprint 1)
id, task_id FK tasks (CASCADE),
project_id FK projects (CASCADE)
  -- Denormalised from task.project_id so the project Time tab
  -- can aggregate by project without joining tasks. Kept in
  -- sync by TimeEntryController at create time.
user_id FK users (RESTRICT)
  -- Restrict so deleting a user with billable hours surfaces the
  -- accounting question instead of silently dropping the data.
minutes UNSIGNED INT
  -- Stored as minutes. UI converts to hours. Avoids float drift.
description TEXT nullable,
logged_at DATE, is_billable BOOLEAN DEFAULT true,
hourly_rate DECIMAL(8,2) nullable
  -- Per-entry rate override; otherwise project.hourly_rate.
invoice_line_id FK invoice_lines nullable (SET NULL),
invoice_id FK invoices nullable (SET NULL)
  -- Stamped when the entry is rolled into an invoice. Once set,
  -- the entry is frozen — TimeEntryController refuses edits and
  -- deletes until the invoice is voided.
created_at, updated_at
INDEX (project_id, is_billable, invoice_id) -- unbilled lookups
INDEX (task_id)
INDEX (user_id, logged_at)

## project_files
id, project_id FK projects (CASCADE),
task_id FK tasks nullable (SET NULL)
  -- Null = project-level file; set when the file is attached to a
  -- task. SET NULL so deleting a task demotes its files to
  -- project-level instead of losing them.
filename VARCHAR(255), stored_name VARCHAR(255)
  -- filename is the original upload name (display only); stored_name
  -- is the UUID name the file lives under on the private disk.
path VARCHAR(500), mime_type VARCHAR(100),
size_bytes UNSIGNED INT,
scan_status ENUM(pending|clean|infected|error|skipped)
  DEFAULT 'pending',
scan_completed_at TIMESTAMP nullable, scan_result TEXT nullable
  -- Async ClamAV lifecycle: rows start pending; the scan pass moves
  -- them to clean/infected/error/skipped and stamps the verdict.
uploaded_by FK users (RESTRICT)
  -- Restrict so a user with files can't be hard-deleted out from
  -- under the audit trail.
description VARCHAR(255) nullable,
created_at, updated_at
INDEX (project_id, scan_status)
INDEX (task_id)
INDEX (uploaded_by)

## activity_log
id, user_id BIGINT nullable, user_role VARCHAR(50) nullable,
action VARCHAR(100), entity_type VARCHAR(100),
entity_id BIGINT nullable,
before JSON nullable, after JSON nullable,
ip_address VARCHAR(45) nullable,
user_agent VARCHAR(500) nullable,
created_at
-- Append-only. No updated_at. No soft deletes.

## onboarding_sequences
id, product_id FK, name VARCHAR(255),
is_active BOOLEAN DEFAULT true,
steps JSON, created_at, updated_at

## customer_onboarding_progress
id, customer_id FK, sequence_id FK,
current_step INT DEFAULT 0,
completed_at nullable, created_at, updated_at

## settings
key VARCHAR(255) UNIQUE, value TEXT nullable, updated_at

## webhook_events
id, source VARCHAR(50), event_id VARCHAR(255),
event_type VARCHAR(100), payload JSON,
processed_at nullable, created_at
-- UNIQUE(source, event_id) — idempotency key. No updated_at.

## webhook_deliveries (Webhooks + Auto-Suspension sprint)
id, event_type VARCHAR(100)
  -- e.g. customer_product.suspended
product_slug VARCHAR(50)
  -- maavelus, myorderpad, …
payload JSON, target_url VARCHAR(500),
signature VARCHAR(100)
  -- HMAC-SHA256 of the payload; consumers verify with hash_equals().
status ENUM(pending|delivered|failed|abandoned) DEFAULT 'pending',
http_status UNSIGNED INT nullable, response_body TEXT nullable,
attempts UNSIGNED TINYINT DEFAULT 0,
max_attempts UNSIGNED TINYINT DEFAULT 3,
delivered_at nullable, next_retry_at nullable,
created_at, updated_at
INDEX (product_slug, status)
INDEX (event_type, created_at)
INDEX (status, next_retry_at) -- retry-sweep lookup
-- OUTBOUND ledger — sibling of the inbound webhook_events above.
-- WebhookDispatcher writes the row before sending; the DeliverWebhook
-- job (or the retry sweep) updates status/attempts/next_retry_at.

## forms (Forms sprint)
id,
name VARCHAR(255), description TEXT nullable,
slug VARCHAR(100) UNIQUE
  -- Used in /forms/{slug}/embed.js, /forms/{slug}/submit,
  -- and /webhooks/{slug}. Regex: ^[a-z0-9-]+$.
status ENUM(active|inactive|draft) DEFAULT 'draft',
theme_id FK form_themes nullable nullOnDelete
  -- Forms theming Phase 2a. NULL = default design tokens
  -- (the widget's original hardcoded look). See form_themes.
submit_button_text VARCHAR(100) DEFAULT 'Submit',
success_message TEXT nullable,
redirect_url VARCHAR(500) nullable,
gdpr_consent_enabled BOOLEAN DEFAULT false,
gdpr_consent_text TEXT nullable,
webhook_secret VARCHAR(64)
  -- HMAC-SHA256 key for the inbound webhook route.
  -- VerifyFormWebhookSignature middleware reads it.
submission_count INT DEFAULT 0
  -- Denormalised; incremented per successful submit.
created_by FK users RESTRICT,
created_at, updated_at
-- INDEX (slug, status) forms_public_lookup_idx

## form_fields (Forms sprint)
id, form_id FK forms CASCADE,
form_step_id FK form_steps CASCADE nullable
  -- Multi-step: which step this field belongs to. NULL = legacy
  -- single-step form (no form_steps rows). Multi-step sprint.
label VARCHAR(255),
content TEXT nullable
  -- Display text for 'placeholder' fields only (sanitised HTML subset via
  -- FormContentSanitizer); NULL for every other type. Added
  -- 2026_06_19_110000 after label's VARCHAR(255) truncated rich
  -- placeholder text; the migration backfilled content from label and
  -- blanked label for existing placeholder rows.
field_key VARCHAR(100)
  -- POST field name; ^[a-z][a-z0-9_]*$ enforced by builder.
type ENUM(text|email|phone|textarea|select|radio
  |checkbox|number|date|hidden|placeholder|datetime) DEFAULT 'text',
  -- 'datetime' (Date & Time field) renders an <input type="datetime-local">
  -- (date + time picker); separate from 'date'. Enum widened by
  -- 2026_06_20_100000_add_datetime_to_form_fields_type.
  -- 'placeholder' (Multi-step sprint) is a DISPLAY-ONLY text block: no
  -- respondent input, excluded from submit validation + answer collection.
  -- The `content` column holds its display text (`label` is emptied for
  -- placeholders since 2026_06_19_110000 — see content above); `field_key`
  -- is auto-synthesised server-side as placeholder_{sort_order} when the
  -- builder omits it, and is_required is forced false. Enum widened by
  -- 2026_06_19_100000_add_placeholder_to_form_fields_type.
placeholder VARCHAR(255) nullable,
default_value VARCHAR(255) nullable,
options JSON nullable
  -- For select/radio: ["Option 1","Option 2"].
is_required BOOLEAN DEFAULT false,
width VARCHAR(16) DEFAULT 'full'
  -- Layout width in the widget's 12-col grid: full|half|third
  -- (App\Enums\FieldWidth → 12/6/4). DEFAULT 'full' = today's
  -- single-column stack. Grid collapses to 1 col on narrow
  -- CONTAINERS (CSS @container), not viewport.
validation_rules JSON nullable,
sort_order INT DEFAULT 0, created_at, updated_at
  -- STEP-scoped since the multi-step sprint: order WITHIN a step,
  -- not across the whole form. Form::fields() orders by
  -- form_step_id then sort_order.
-- INDEX (form_id, form_step_id, sort_order) form_fields_form_id_step_sort_index
--   Multi-step sprint: replaced the old (form_id, sort_order) index
--   (was form_fields_order_idx) now that sort_order is step-scoped.

## form_steps (Multi-step forms sprint)
id, form_id FK forms CASCADE,
label VARCHAR(255)
  -- Step heading shown in the respondent progress indicator
  -- (e.g. "Your Details"). See /design/forms-multistep.html.
sort_order INT DEFAULT 0
  -- Orders the steps within a form.
created_at, updated_at
-- INDEX (form_id, sort_order) form_steps_form_id_sort_order_index
-- Single-step forms have NO rows here; their fields carry a null
-- form_fields.form_step_id. A multi-step form's fields reference a
-- step via that FK.

## form_submissions (Forms sprint)
id, form_id FK forms RESTRICT
  -- RESTRICT because a deleted form would orphan the
  -- submission's origin. Retire forms via status=inactive.
data JSON
  -- All non-framework POST values verbatim.
status ENUM(new|processed|spam|error) DEFAULT 'new',
ip_address VARCHAR(45) nullable, user_agent TEXT nullable,
referrer_url VARCHAR(500) nullable,
lead_id FK leads nullable SET NULL
  -- Back-stamped by WorkflowEngine when create_lead fires.
created_at, updated_at
-- INDEX (form_id, status, created_at) form_submissions_funnel_idx
-- INDEX (lead_id)                     form_submissions_lead_idx

## form_submission_drafts (Multi-step forms sprint)
id, form_id FK forms CASCADE
  -- CASCADE (not RESTRICT like form_submissions): a draft is
  -- in-progress state, not business history, so it dies with the form.
draft_token VARCHAR(64) UNIQUE
  -- Anonymous resume credential, localStorage-keyed in the embed
  -- widget. ALWAYS generated (anonymous or authenticated) via
  -- FormSubmissionDraft::generateToken(id) — sha256 of
  -- id + Str::random(32) + app.key (mirrors proposals.acceptance_token).
portal_user_id FK portal_users CASCADE nullable
  -- Authenticated Portal respondent. NULL = anonymous.
current_step INT DEFAULT 0
  -- Last completed step (0 = not started, 1 = step 1 done, …).
data JSON nullable
  -- Partial answers accumulated across steps, keyed by field_key.
  -- Named `data` for symmetry with form_submissions.data: on final
  -- submit the draft is promoted to a form_submissions row (status
  -- 'new', fires the WorkflowEngine) and the draft row is deleted.
ip_address VARCHAR(45) nullable, user_agent TEXT nullable,
referrer_url VARCHAR(500) nullable,
  -- Abuse/audit parity with form_submissions for promotion.
expires_at TIMESTAMP nullable
  -- Draft TTL. Anonymous drafts expire; authenticated drafts may
  -- leave this null.
created_at, updated_at
-- INDEX (form_id, portal_user_id) form_submission_drafts_form_portal_index
--   Authenticated resume lookup.
-- INDEX (expires_at)              form_submission_drafts_expires_at_index
--   Expired-row cleanup sweep.
-- UNIQUE(draft_token) — anonymous resume lookup.

## form_themes (Forms theming — Phase 2a)
id,
name VARCHAR(255),
tokens JSON
  -- PARTIAL override set of design tokens, e.g.
  -- {"accent":"#0ea5e9","radius":"12px","full_width":true}.
  -- Effective values = App\Support\FormThemeTokens::defaults()
  -- merged with these (theme wins; absent/null keys fall back),
  -- so a theme only carries the keys it changes. Token keys:
  -- font_family, font_size, text, label, accent, focus_ring,
  -- background, surface, border, border_width, radius,
  -- button_bg, button_bg_hover, button_text, error,
  -- success_bg, success_border, success_text,
  -- button_style(solid|outline), full_width(bool),
  -- button_hover(none|lift|glow|shine|fill; default lift),
  -- button_icon(none|arrow|send|chevron|check|download; default none),
  -- button_icon_position(leading|trailing; default trailing),
  -- logo_url, heading, custom_css,
  -- form_padding, form_border_width, form_border_radius,
  -- form_border_color (form-container box; defaults 0/0/0/neutral
  -- = no effect, so existing themes render unchanged).
created_by FK users RESTRICT,
created_at, updated_at
-- Standalone + reusable: NOT coupled to the websites module.
-- Linked from forms.theme_id (nullable, nullOnDelete).
-- Phase 2b: managed via the design editor (Internal\FormThemeController,
-- /forms/themes, FormThemePolicy = isStaff). The custom_css token is
-- super_admin-only (manageCustomCss) — read- AND write-gated, stripped
-- from non-super-admin payloads and never persisted from them.

## workflows (Forms sprint)
id, name VARCHAR(255), description TEXT nullable,
is_active BOOLEAN DEFAULT true,
trigger_type ENUM(form_submitted|webhook_received
  |lead_created|lead_status_changed|manual),
trigger_config JSON nullable
  -- {"form_id": 4}, {"to": "qualified"}, {"source": "mailchimp"}.
conditions JSON nullable
  -- Optional field-value gate evaluated by WorkflowEngine AFTER the
  -- trigger_config match and BEFORE the action transaction. NULL/empty
  -- = no gating (fires as before). Shape: {logic, groups:[{logic,
  -- conditions:[{field_key, operator, value}]}]} — OR between groups,
  -- AND within a group by default (stored logic honoured). See
  -- App\Services\WorkflowConditionEvaluator (the 12 operators).
run_count INT DEFAULT 0, last_run_at TIMESTAMP nullable,
created_by FK users RESTRICT,
created_at, updated_at
-- INDEX (trigger_type, is_active) workflows_dispatch_idx

## workflow_actions (Forms sprint)
id, workflow_id FK workflows CASCADE,
action_type ENUM(create_lead|update_lead_status
  |create_task|create_ticket|assign_to_user|add_note
  |send_notification|add_to_group|webhook_outbound|send_email),
  -- Enum widened by 2026_06_03_120001 (create_ticket) and
  -- 2026_06_17_120000 (send_email) — raw MODIFY COLUMN, native MySQL ENUM.
config JSON
  -- Action-specific; see WorkflowEngine docblock.
sort_order INT DEFAULT 0, created_at, updated_at
-- INDEX (workflow_id, sort_order) workflow_actions_order_idx
-- Engine reads actions ORDER BY sort_order — earlier actions
-- accumulate context (e.g. create_lead writes lead_id) that
-- later actions consume (create_task reads lead_id).

## workflow_runs (Workflow audit sprint, 2026_07_09)
id, workflow_id FK workflows CASCADE,
trigger_type VARCHAR(50)
  -- Stored as a string, NOT the workflows.trigger_type ENUM — the trigger
  -- set is widening and the ledger must record a new one without a migration.
trigger_entity_id BIGINT UNSIGNED nullable,
status ENUM(succeeded|failed|skipped),
error TEXT nullable
  -- throwing action's message on failed; guard/skip reason on skipped.
duration_ms INT UNSIGNED nullable,
context_summary JSON nullable
  -- resolved ids only: {lead_id, ticket_id, customer_id, submission_id}.
actions JSON nullable
  -- per-action: [{action_id, action_type, sort_order, outcome
  --   (ran|skipped|failed), skip_reason, error, duration_ms}]. NULL for a
  --   workflow-level skip (loop guard) where no actions were considered.
created_at
-- Append-only. No updated_at. No soft deletes (activity_log's convention).
-- INDEX (workflow_id, created_at) workflow_runs_workflow_created_idx
-- ONE row per workflow FIRING, written by WorkflowEngine OUTSIDE the
-- per-workflow transaction (run loop's finally / loop-guard skip), so a
-- failed run whose transaction rolled back STILL leaves a status=failed
-- record — the gap the old in-transaction workflow.executed row could not
-- fill. On a failed run, per-action entries marked outcome=ran executed
-- without error but were rolled back with the run (status=failed signals it).

---

## Roles & permissions (Spatie laravel-permission + scope table)

Added in the roles & permissions Phase 1 build. **INERT in phase 1** —
populated and access-identical to the current enum, but read by no route /
policy / controller for authorization yet; enforcement still runs off
`users.role` (super_admin|staff|referrer). Spatie tables created by its
published `create_permission_tables` migration; `role_scopes` is custom.

Spatie tables (standard, guard_name = 'web' for internal roles):

## permissions (Spatie)
id, name, guard_name, created_at, updated_at
UNIQUE (name, guard_name)

## roles (Spatie)
id, name, guard_name, created_at, updated_at
UNIQUE (name, guard_name)
-- teams feature OFF (config/permission.php teams=false)

## model_has_permissions (Spatie)
permission_id FK permissions CASCADE, model_type, model_id (morph)
PRIMARY KEY (permission_id, model_id, model_type)
INDEX (model_id, model_type)

## model_has_roles (Spatie)
role_id FK roles CASCADE, model_type, model_id (morph)
PRIMARY KEY (role_id, model_id, model_type)
INDEX (model_id, model_type)
-- internal users (App\Models\User) are attached here via HasRoles

## role_has_permissions (Spatie)
permission_id FK permissions CASCADE, role_id FK roles CASCADE
PRIMARY KEY (permission_id, role_id)

## role_scopes (custom — tri-state section access)
id, role_id FK roles CASCADE,
area ENUM(projects|tasks|leads|support),
scope ENUM(all|assigned|none),
created_at, updated_at
UNIQUE (role_id, area)   -- one scope per (role, area): invalid states unrepresentable
INDEX  (area, scope)     -- "which roles have area=X at scope=Y" (audit query)
-- area/scope backed by App\Enums\ScopeArea / App\Enums\AccessScope (code
   source of truth; the RoleScope model casts to them). Absence of a
   (role, area) row is read as None by the future resolver.

---

## API key storage rule (no schema changes — convention)
Whenever a future table stores an API key issued *by us* (e.g. for
external products to call back into Powerhouse), the key column
must store `hash('sha256', $rawKey)` only. The raw key is shown to
the user once on creation and never again. Compare with `hash_equals()`.
