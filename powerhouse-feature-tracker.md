# Powerhouse — Feature Tracker

> **Status of this file:** No `powerhouse-feature-tracker.md` existed in the repo
> (root or `/docs`) when this reconcile ran — this is a **fresh baseline rebuilt
> from the codebase**, so there were no prior checkboxes to drift against
> (nothing "checked-but-not-in-code" could be computed). Every status below is
> verified against actual controllers / routes / models / migrations, cited in
> **Evidence**. Tier numbers are a reconstructed grouping — re-map to the team's
> canonical scheme if one exists.
>
> Legend: ✅ **Built** · 🟡 **Partial** · ⬜ **Not-started**
> Last reconciled: 2026-06-05.

---

## Tier 1 — Identity, auth & access
| Item | Status | Evidence |
|---|---|---|
| Staff auth (session, roles super_admin/staff) | ✅ | `Auth/StaffLoginController`, `role:` middleware, `EnsureRole` |
| Customer portal auth (separate guard) | ✅ | `Portal/AuthController`, `auth.portal`/`EnsurePortalUser`, `PortalUser` |
| Referrer/partner portal auth | ✅ | `role:referrer` group, `Referrer/AuthController`, preview/exit |
| Login screens have hub nav | ✅ | `Auth/Login.vue`, `Portal/{Login,ForgotPassword,ResetPassword}.vue` (logo→`/`, "Back to hub") |
| Impersonation / preview | ✅ | `Internal/ImpersonationController`, `/impersonate/referrer/{id}`, portal/referrer preview routes |

## Tier 2 — CRM: leads pipeline
| Item | Status | Evidence |
|---|---|---|
| Leads kanban + list, status pipeline | ✅ | `Internal/LeadController` (index/updateStatus), `Leads/Index.vue`, `leads.status` enum |
| Lead → customer conversion | ✅ | `LeadController::convert()` (creates Customer + primary Contact, migrates tasks/notes) |
| Lead capture from public forms / workflows | ✅ | `Public/FormController`, `WorkflowEngine::actionCreateLead` |

## Tier 3 — Customers, contacts & people
| Item | Status | Evidence |
|---|---|---|
| Customers CRUD + detail | ✅ | `Internal/CustomerController`, `Customers/{Index,Show}.vue` |
| Contacts (per-customer) | ✅ | `Internal/ContactController`, `Contact` model |
| People ↔ companies (multi-company humans) | ✅ | `people` + `customer_person` migrations, `Person`/`CustomerPerson`, `PersonService`, `PersonController`, `Internal/People/*` |
| Customer groups | ✅ | `Internal/CustomerGroupController` |
| GDPR (export/erasure) | ✅ | `Internal/GdprController`, `/gdpr/customers/*`, erasure columns on `customers` |

## Tier 4 — Products, plans & pricing
| Item | Status | Evidence |
|---|---|---|
| Products + plans + categories + prices CRUD | ✅ | `Internal/{Product,ProductPlan,ProductPlanCategory,ProductPlanPrice}Controller`, `Settings/Products.vue` |
| Product cost lines (suppliers/margin) | ✅ | `Internal/ProductSupplierController`, `product_suppliers` |
| Public pricing API | ✅ | `routes/api.php` → `api/pricing/{slug}` (`PricingController`) |

## Tier 5 — Billing & invoicing
| Item | Status | Evidence |
|---|---|---|
| Invoices (CRUD, PDF, send, void) | ✅ | `Internal/InvoiceController` (incl. `invoice.voided`) |
| Recurring/subscription invoice generation | ✅ | `Console/Commands/GenerateSubscriptionInvoices`, `invoices.type='subscription'` |
| Stripe payment (embedded checkout) | ✅ | `StripeService::createEmbeddedCheckoutSession` / `markInvoicePaid`, `Webhooks/StripeWebhookController` |
| Portal invoice list + pay + paid state | ✅ | `Portal/InvoiceController`, `Portal/{Invoices,InvoiceDetail,InvoicePaid}.vue` |
| Payment schedules | ✅ | `Internal/PaymentScheduleController` |
| Expenses + suppliers register | ✅ | `Internal/ExpenseController`, `Internal/SupplierController` |
| Auto-suspend on overdue + auto-reinstate on pay | ✅ | `Console/Commands/ProcessSuspensions`, `StripeService::autoReinstate` |
| Maavelus monthly revenue statements | ✅ | `Internal/MaavelusStatementController`, `MaavelusStatementService`, `maavelus_statements` |

## Tier 6 — Referrals & commissions
| Item | Status | Evidence |
|---|---|---|
| Attribution Phase 1 (codes, clicks, ledger spine) | ✅ | `AttributionService`, `ReferralCodeGenerator`, `referral_clicks`, `Public/ReferralRedirectController` (`/r/{code}`) |
| Immutable `customer_referrals` attribution | ✅ | `customer_referrals` unique(customer_id), `AttributionService::attribute` |
| Commission engine — invoice-paid accrual | ✅ | `CommissionService::accrueForInvoice` hooked in `StripeService::markInvoicePaid`; `CommissionRuleResolver`; unique idx migration `..._add_unique_index_to_commission_ledger` |
| Maavelus statement-based commission | ✅ | `MaavelusStatementService::generateCommissions` (excluded from invoice engine via `referrals.commission_excluded_slugs`) |
| Commission approve / mark-paid lifecycle | ✅ | `Internal/ReferrerController::{approveCommission,approveAll,markPaid}` |
| Commission rules admin UI | ✅ | `Internal/CommissionRuleController`, `CommissionRuleService`, `Settings/CommissionRules.vue` |
| Referrer portal (dashboard, commissions, customers) | ✅ | `Referrer/{Dashboard,Commission,Customer}Controller`, portal pages |
| Commission clawback (refund/churn) | ⬜ | No `charge.refunded` handler in `StripeWebhookController`; only manual `voided` via `CustomerController::removeReferral` |
| Tiered commission (`recurring_tiered`) | ⬜ | `CommissionService::calculate` stubs it to 0 (deferred) |

## Tier 7 — Deal registration
| Item | Status | Evidence |
|---|---|---|
| Register → review → protect loop | ✅ | `DealRegistrationService`, `Referrer/ReferralDealController`, `Referrer/Referrals.vue`, `leads.referral_status` migration |
| Staff review queue + approve/reject | ✅ | `LeadController::{approveReferral,rejectReferral}`, `Leads/Index.vue` review queue |
| 90-day protection + expiry sweep | ✅ | `protected_until` (set on approve), `Console/Commands/ExpireDealProtections` (scheduled `routes/console.php`) |
| Net-new dedup (non-revealing) | ✅ | `DealRegistrationService::assertNetNew` |

## Tier 8 — Provisioning & hosting lifecycle  *(emphasis)*
| Item | Status | Evidence |
|---|---|---|
| Provisioning dashboard + toggle | ✅ | `Internal/ProvisioningController` (index/toggle), `/provisioning`, `Provisioning/Index.vue` |
| Per-subscription suspend / reinstate (reasoned, webhook-firing) | ✅ | `Internal/CustomerProductController::{suspend,reinstate}`, `/customer-products/{id}/suspend|reinstate` |
| cPanel / WHM integration | ✅ | `CpanelService`, `WhmService`, `Internal/WebsiteController` (`/websites`, PageSpeed) |
| MyOrderPad provisioning | ✅ | `MyOrderPadProvisioningService`, `Jobs/ProvisionMyOrderPad`, `ProvisioningService` |
| Auto-suspend sweep (overdue) → auto-reinstate (paid) | ✅ | `Console/Commands/ProcessSuspensions`; `StripeService::autoReinstate` only undoes system (non-staff) suspensions |
| Branded OAuth suspension page | ✅ | `OAuth/SuspensionController`, `/oauth/suspended` |
| WordPress update management | ✅ | `Internal/WordPressUpdateController`, `/wordpress/updates` (super_admin) |

## Tier 9 — SSO / connected apps (OAuth)  *(emphasis)*
| Item | Status | Evidence |
|---|---|---|
| Passport OAuth server (authorize/token) | ✅ | Laravel Passport; `OAUTH-FLOW.md` |
| `/oauth/userinfo` profile call | ✅ | `OAuth/UserInfoController::me`, `oauth.userinfo` (auth:api) |
| `/oauth/products` per-product access map | ✅ | `OAuth/UserInfoController::products`, `oauth.products` |
| Server-side SSO launcher (per-launch token) | ✅ | `Portal/ProductLaunchController::launch`, `ResolvesProductLaunch`, `/portal/launch/{slug}` |
| Connected-apps revoke | ✅ | `Portal/ConnectedAppController::revoke` |
| **`/powerhouse/summary` endpoint** | ⬜ | **No such route** in `web.php` or `api.php`. The product-access "summary" is served by `/oauth/userinfo` + `/oauth/products`; the literal `/powerhouse/summary` name is not implemented. |

## Tier 10 — Support portal  *(emphasis — beyond ticketing/email/SLA)*
| Item | Status | Evidence |
|---|---|---|
| Staff ticketing (queue, reply, status, assign) | ✅ | `Internal/SupportController` (index/show/store/reply/updateStatus/createTask), `assigned_to` |
| Inbound email → ticket (Postmark, threading) | ✅ | `Public/InboundEmailController::receive`, `/webhooks/email/inbound` |
| SLA breach stamp | ✅ | `support_tickets.sla_breach_at` (single computed deadline) |
| Guest tickets (no account) | ✅ | `support_tickets` nullable customer + `guest_*`; `StoreSupportTicketRequest` |
| Single intake path (form + workflow action) | ✅ | `TicketIntakeService`, `Public/SupportTicketController`, `WorkflowEngine` create_ticket |
| Ack + staff-alert + threaded reply mailables | ✅ | `Mail/SupportTicketReceived`, `Mail/SupportTicketStaffAlert`, `Mail/SupportTicketReply` (guest_email fallback) |
| Triage task auto-created + linked to ticket | ✅ | `TicketIntakeService` + `tasks.ticket_id` (`..._add_ticket_id_to_tasks`) |
| Customer portal ticket view + reply | ✅ | `Portal/SupportController`, `Portal/Support/{Index,Show}.vue` |
| Public knowledge base | ✅ | `Public/KnowledgeBaseController`, `KbContentService`, `Public/KnowledgeBase/*` |
| Staff help/KB authoring | ✅ | `Internal/HelpController`, `/help` |
| AI triage / sentiment | ⬜ | `support_tickets.sentiment_score` + `support_messages.ai_confidence/ai_model` columns exist but **no code writes them** — schema scaffolding only |
| Satisfaction rating (CSAT) | ⬜ | No satisfaction/rated_at column or flow |
| Richer SLA (first_responded_at, reopened_at, SLA reporting) | ⬜ | Not in schema; SLA is a single `sla_breach_at` stamp |

## Tier 11 — Analytics & reporting  *(emphasis)*
| Item | Status | Evidence |
|---|---|---|
| Analytics dashboard (revenue/customer-centric) | 🟡 | `Internal/AnalyticsController`: headline (MRR, churn, ARPC), `mrr_trend`, `by_product`, `customer_growth`, `top_referrers`, `plan_popularity` |
| Support / SLA analytics | ⬜ | None in `AnalyticsController` |
| Commission / payout analytics | ⬜ | None in `AnalyticsController` (only ledger sums in ReferrerController) |
| Lead-funnel / conversion analytics | ⬜ | Only Leads-page summary KPIs; no funnel analytics view |

## Tier 12 — Public hub & marketing
| Item | Status | Evidence |
|---|---|---|
| Public front door `/` (auth-branching landing) | ✅ | `Public/LandingController`, `PublicLayout.vue`, `Landing.vue` |
| Mobile burger nav (public + internal + portal responsive) | ✅ | `PublicLayout.vue`, `InternalLayout.vue` off-canvas drawer, `app.css` ≤720/≤920 chrome |
| Proposals + public acceptance | ✅ | `Internal/ProposalController`, `Public/ProposalView.vue`, token acceptance flow |
| Contracts | ✅ | `Internal/ContractController` |
| Form builder + submissions + embed/webhook | ✅ | `Internal/FormBuilderController`, `Public/FormController` |
| Workflows (triggers/actions) | ✅ | `WorkflowEngine`, `Internal/WorkflowController` |

## Tier 13 — Project management & ops
| Item | Status | Evidence |
|---|---|---|
| Projects / milestones / tasks / time entries | ✅ | `Internal/{Project,Milestone,Task,TimeEntry}Controller`, `MyWork.vue` |
| Google Calendar sync | ✅ | `Internal/GoogleCalendarAuthController`, `GoogleCalendarService` |
| Domains register | ✅ | `Internal/DomainController`, `/domains` |
| Notifications (in-app + prefs) | ✅ | `Internal/NotificationController`, nav badge cache keys |

## Tier 14 — Settings & platform admin
| Item | Status | Evidence |
|---|---|---|
| Settings (team, security, billing automation, integrations, audit log, danger) | ✅ | `Internal/SettingsController`, `SettingsLayout.vue` |
| Billing entities (multi-entity invoicing) | ✅ | `Internal/BillingEntityController` |
| Reminder templates | ✅ | `SettingsController::reminderTemplates*` |
| Commission rules (Settings tab) | ✅ | `Internal/CommissionRuleController` (Tier 6) |

---

### Cross-cutting gaps (verified absent)
- Commission **clawback** on refund/subscription-cancel (Tier 6).
- `recurring_tiered` commission calc (Tier 6).
- Literal **`/powerhouse/summary`** endpoint (Tier 9) — use `/oauth/userinfo` + `/oauth/products`.
- **AI triage / sentiment** + **CSAT** + richer **SLA timeline** in Support (Tier 10) — columns scaffolded, no logic.
- **Support / commission / lead-funnel analytics** (Tier 11).
