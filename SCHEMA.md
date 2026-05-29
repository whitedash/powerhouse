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
assigned_to BIGINT FK users nullable,
referred_by BIGINT FK referrers nullable,
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
icon_colour, is_active BOOLEAN, is_coming_soon BOOLEAN,
sort_order INT, created_at, updated_at

## customer_products
id, customer_id FK, product_id FK,
billing_entity_id FK nullable,
plan VARCHAR(100) nullable, price_monthly DECIMAL(10,2) nullable,
status ENUM(active|trial|suspended|cancelled),
trial_ends_at nullable, started_at nullable, cancelled_at nullable,
oauth_client_id BIGINT nullable,
wp_user_id BIGINT nullable,
config JSON nullable, created_at, updated_at

## billing_entities
id, name, legal_name, company_number, vat_number,
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
status ENUM(draft|sent|paid|overdue|void),
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
id, invoice_id FK, description VARCHAR(500),
note VARCHAR(500) nullable, quantity DECIMAL(10,3),
unit_price DECIMAL(10,2), amount DECIMAL(10,2),
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
id, product_id FK nullable, title VARCHAR(255),
body TEXT, status ENUM(draft|published),
created_by FK users, published_at nullable,
created_at, updated_at

## notes
id, customer_id FK, created_by FK users,
type ENUM(internal|call|meeting|email),
body TEXT, created_at, updated_at

## tasks
id, customer_id FK nullable, assigned_to FK users,
created_by FK users, title VARCHAR(500),
status ENUM(open|complete),
due_date DATE nullable, completed_at nullable,
created_at, updated_at

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

---

## API key storage rule (no schema changes — convention)
Whenever a future table stores an API key issued *by us* (e.g. for
external products to call back into Powerhouse), the key column
must store `hash('sha256', $rawKey)` only. The raw key is shown to
the user once on creation and never again. Compare with `hash_equals()`.
