# Powerhouse — Database Schema
*Source of truth. Never add or remove columns without updating this file.*

## users
id, name, email, password, role ENUM(super_admin|staff|referrer),
avatar_colour, two_factor_secret TEXT (encrypted),
two_factor_confirmed_at,
last_login_at, last_login_ip, created_at, updated_at

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
referred_by BIGINT FK referrers nullable,
qbo_customer_id VARCHAR(100) nullable UNIQUE
  -- QuickBooks Online customer id. Populated by a future QBO sync.
archived_at nullable, created_at, updated_at

## contacts
id, customer_id FK, name, email, phone nullable,
role ENUM(owner|manager|accounts|other),
is_primary BOOLEAN DEFAULT false, created_at, updated_at

## account_groups
id, name, created_at, updated_at

## customer_group_memberships
id, group_id FK, customer_id FK,
role ENUM(owner|member), created_at

## portal_users
id, customer_id FK, name, email, password,
two_factor_secret nullable, two_factor_confirmed_at nullable,
email_verified_at nullable, last_login_at nullable,
created_at, updated_at

## products
id, slug VARCHAR(50) UNIQUE, name, description,
billing_entity_id FK billing_entities nullable (SET NULL)
  -- Default billing entity for invoices that include this product;
  -- null = universal (operator picks the entity per invoice).
icon_colour, is_active BOOLEAN, is_coming_soon BOOLEAN,
sort_order INT,
qbo_item_id VARCHAR(100) nullable UNIQUE
  -- QuickBooks Online item id. Populated by a future QBO sync.
created_at, updated_at

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
name VARCHAR(100), description TEXT nullable,
features JSON nullable,
is_active BOOLEAN DEFAULT true,
is_public BOOLEAN DEFAULT true,
sort_order INT DEFAULT 0,
created_at, updated_at
INDEXES: (product_id, is_active, sort_order)

## product_plan_prices
id, plan_id FK product_plans CASCADE,
price DECIMAL(10,2) DEFAULT 0,
interval_count TINYINT UNSIGNED DEFAULT 1,
interval_unit ENUM(day|week|month|year|one_time) DEFAULT 'month',
stripe_price_id VARCHAR(100) nullable,
label VARCHAR(100) nullable,
is_default BOOLEAN DEFAULT false,
is_active BOOLEAN DEFAULT true,
sort_order INT DEFAULT 0,
created_at, updated_at
INDEXES: (plan_id, is_active, sort_order)

## customer_products
id, customer_id FK, product_id FK,
plan_id FK product_plans nullable ON DELETE SET NULL,
plan_price_id FK product_plan_prices nullable ON DELETE SET NULL,
billing_entity_id FK nullable,
stripe_subscription_id VARCHAR(100) nullable,
stripe_price_id VARCHAR(100) nullable,
plan VARCHAR(100) nullable, price_monthly DECIMAL(10,2) nullable,
interval_count TINYINT UNSIGNED DEFAULT 1,
interval_unit ENUM(day|week|month|year|one_time) DEFAULT 'month',
status ENUM(active|trial|suspended|cancelled),
trial_ends_at nullable, started_at nullable,
next_billing_date DATE nullable,
discount_pct DECIMAL(5,2) nullable,
discount_expires_at DATE nullable,
cancels_at DATE nullable,
cancelled_at nullable,
oauth_client_id BIGINT nullable,
wp_user_id BIGINT nullable,
config JSON nullable, created_at, updated_at
INDEXES: (customer_id, product_id), status, next_billing_date

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
subtotal DECIMAL(10,2), vat_rate DECIMAL(5,2),
vat_amount DECIMAL(10,2), total DECIMAL(10,2),
amount_paid DECIMAL(10,2) DEFAULT 0,
issue_date DATE, due_date DATE, paid_at nullable,
payment_method ENUM(bank_transfer|card|direct_debit|other) nullable,
payment_reference VARCHAR(255) nullable,
notes TEXT nullable, pdf_path VARCHAR(500) nullable,
sent_at nullable, qbo_invoice_id VARCHAR(100) nullable,
reminder_count INT UNSIGNED DEFAULT 0,
last_reminder_sent_at nullable,
next_reminder_at nullable,
reminders_paused BOOLEAN DEFAULT FALSE,
created_by BIGINT FK users, created_at, updated_at
INDEX (next_reminder_at, reminders_paused, status)

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
discount_value DECIMAL(10,2) DEFAULT 0,
discount_amount DECIMAL(10,2) DEFAULT 0
  -- Cooked discount £ — stored for audit; never recomputed on read.
sort_order INT DEFAULT 0, created_at, updated_at

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
payment_details JSON nullable,
is_active BOOLEAN DEFAULT true, created_at, updated_at

## customer_referrals
id, customer_id BIGINT UNIQUE FK,
referrer_id FK, attributed_at, created_at

## commission_rules
id, referrer_id FK nullable,
product_id FK,
type ENUM(one_off_pct|recurring_tiered|hybrid),
config JSON,
valid_from DATE, valid_until DATE nullable,
is_active BOOLEAN DEFAULT true, created_at, updated_at

## commission_ledger
id, referrer_id FK, customer_id FK,
invoice_id FK nullable, rule_id FK, product_id FK,
trigger_type ENUM(onboarding|invoice_paid|monthly_recurring),
gross_amount DECIMAL(10,2), commission_amount DECIMAL(10,2),
status ENUM(pending|approved|paid|voided),
period_start DATE nullable, period_end DATE nullable,
approved_by BIGINT FK users nullable, approved_at nullable,
paid_at nullable, voided_reason VARCHAR(500) nullable,
created_at, updated_at

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
cloudflare_zone_id VARCHAR(100) nullable,
registrar VARCHAR(100) nullable,
is_in_cloudflare BOOLEAN DEFAULT false,
is_proxied BOOLEAN DEFAULT false,
expiry_date DATE nullable, ssl_expiry_date DATE nullable,
hosting_provider VARCHAR(100) nullable,
hosting_renewal_date DATE nullable,
hosting_notes TEXT nullable,
last_synced_at nullable, created_at, updated_at

## contracts
id, customer_id FK, created_by FK users,
type ENUM(service_agreement|sow|retainer|nda|other),
title VARCHAR(255), value DECIMAL(10,2) nullable,
status ENUM(draft|sent|signed|countersigned|expired|void),
sent_at nullable, signed_at nullable,
signed_ip VARCHAR(45) nullable,
countersigned_at nullable,
start_date DATE nullable, end_date DATE nullable,
pdf_path VARCHAR(500) nullable,
notes TEXT nullable, created_at, updated_at

## support_tickets
id, customer_id FK, contact_id FK nullable,
product_id FK nullable, subject VARCHAR(500),
status ENUM(open|in_progress|awaiting_customer|resolved|closed),
priority ENUM(low|medium|high|urgent),
assigned_to BIGINT FK users nullable,
sentiment_score DECIMAL(3,2) nullable,
sla_breach_at nullable, resolved_at nullable,
closed_at nullable, created_at, updated_at

## support_messages
id, ticket_id FK,
sender_type ENUM(customer|staff|ai),
sender_id BIGINT nullable,
body TEXT, is_internal_note BOOLEAN DEFAULT false,
ai_confidence DECIMAL(3,2) nullable,
ai_model VARCHAR(100) nullable,
created_at, updated_at

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
  |email|phone|event|word_of_mouth|other) DEFAULT 'manual',
source_detail VARCHAR(255) nullable,
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
type ENUM(internal|call|meeting|email),
body TEXT, created_at, updated_at

## tasks
id, customer_id FK nullable,
project_id FK projects nullable (PM Sprint 1),
milestone_id FK milestones nullable (PM Sprint 1),
lead_id FK leads nullable SET NULL (Leads sprint)
  -- Column landed empty in PM Sprint 1. The FK was added by
  -- the leads migration once the referenced table existed.
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
-- Migration 2026_05_30_070004 backfilled the old enum:
--   open → todo, complete → complete. No row was lost.

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

## forms (Forms sprint)
id,
name VARCHAR(255), description TEXT nullable,
slug VARCHAR(100) UNIQUE
  -- Used in /forms/{slug}/embed.js, /forms/{slug}/submit,
  -- and /webhooks/{slug}. Regex: ^[a-z0-9-]+$.
status ENUM(active|inactive|draft) DEFAULT 'draft',
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
label VARCHAR(255), field_key VARCHAR(100)
  -- POST field name; ^[a-z][a-z0-9_]*$ enforced by builder.
type ENUM(text|email|phone|textarea|select|radio
  |checkbox|number|date|hidden) DEFAULT 'text',
placeholder VARCHAR(255) nullable,
default_value VARCHAR(255) nullable,
options JSON nullable
  -- For select/radio: ["Option 1","Option 2"].
is_required BOOLEAN DEFAULT false,
validation_rules JSON nullable,
sort_order INT DEFAULT 0, created_at, updated_at
-- INDEX (form_id, sort_order) form_fields_order_idx

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

## workflows (Forms sprint)
id, name VARCHAR(255), description TEXT nullable,
is_active BOOLEAN DEFAULT true,
trigger_type ENUM(form_submitted|webhook_received
  |lead_created|lead_status_changed|manual),
trigger_config JSON nullable
  -- {"form_id": 4}, {"to": "qualified"}, {"source": "mailchimp"}.
run_count INT DEFAULT 0, last_run_at TIMESTAMP nullable,
created_by FK users RESTRICT,
created_at, updated_at
-- INDEX (trigger_type, is_active) workflows_dispatch_idx

## workflow_actions (Forms sprint)
id, workflow_id FK workflows CASCADE,
action_type ENUM(create_lead|update_lead_status
  |create_task|assign_to_user|add_note
  |send_notification|add_to_group|webhook_outbound),
config JSON
  -- Action-specific; see WorkflowEngine docblock.
sort_order INT DEFAULT 0, created_at, updated_at
-- INDEX (workflow_id, sort_order) workflow_actions_order_idx
-- Engine reads actions ORDER BY sort_order — earlier actions
-- accumulate context (e.g. create_lead writes lead_id) that
-- later actions consume (create_task reads lead_id).

---

## API key storage rule (no schema changes — convention)
Whenever a future table stores an API key issued *by us* (e.g. for
external products to call back into Powerhouse), the key column
must store `hash('sha256', $rawKey)` only. The raw key is shown to
the user once on creation and never again. Compare with `hash_equals()`.
