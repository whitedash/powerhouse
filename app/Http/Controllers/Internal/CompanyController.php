<?php

namespace App\Http\Controllers\Internal;

use App\Enums\PersonRole;
use App\Enums\ScopeArea;
use App\Events\PaginatedListAccessed;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Mail\PortalInvite;
use App\Models\AccountGroup;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\CustomerPerson;
use App\Models\CustomerProduct;
use App\Models\CustomerReferral;
use App\Models\Lead;
use App\Models\Note;
use App\Models\PaymentMethod;
use App\Models\Person;
use App\Models\PortalUser;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanCategory;
use App\Models\ProductPlanPrice;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Referrer;
use App\Models\Task;
use App\Models\User;
use App\Models\Website;
use App\Services\CompanyProvisioningService;
use App\Support\RecurringRevenue;
use App\Support\ScopeEnforcer;
use App\Support\TaskDueDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    private const PIPELINE_STAGES = ['lead', 'prospect', 'active', 'churned'];

    private const TYPE_VALUES = ['restaurant', 'bar', 'bakery', 'cafe', 'venue', 'other'];

    private const CONTACT_ROLES = ['owner', 'manager', 'accounts', 'other'];

    private const NOTE_TYPES = ['internal', 'call', 'meeting', 'email'];

    private const SORT_OPTIONS = ['last_active', 'name', 'created_at'];

    private const PRODUCT_SLUG_TO_PB = [
        'maavelus' => 'maa',
        'myorderpad' => 'mop',
        'whitedash' => 'wdb',
        'smscube' => 'sms',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Company::class);

        if ($request->user()) {
            PaginatedListAccessed::dispatch($request->user()->id, $request->path());
        }

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'pipeline_stage' => $request->query('pipeline_stage'),
            'product_slug' => $request->query('product_slug'),
            'referrer_id' => $request->query('referrer_id'),
            'sort' => in_array($request->query('sort'), self::SORT_OPTIONS, true)
                ? $request->query('sort')
                : 'last_active',
            'per_page' => min(100, max(1, (int) ($request->query('per_page') ?: 20))),
        ];

        $query = Company::query()
            ->with([
                'primaryContact:id,customer_id,name,email',
                'customerProducts.product:id,slug,name,icon_colour',
                // planPrice eager-load is required for mrr_contribution
                // under Model::preventLazyLoading() — the accessor falls
                // back to a manual calc when planPrice is missing.
                'customerProducts.planPrice',
                'referral.referrer.user:id,name,role',
            ]);

        if ($filters['search'] !== '') {
            $needle = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'like', $needle)
                    ->orWhere('trading_name', 'like', $needle)
                    ->orWhereHas('contacts', fn ($c) => $c->where('email', 'like', $needle));
            });
        }

        if (in_array($filters['pipeline_stage'], self::PIPELINE_STAGES, true)) {
            $query->where('pipeline_stage', $filters['pipeline_stage']);
        }

        if ($filters['product_slug']) {
            $query->whereHas(
                'customerProducts',
                fn ($q) => $q->whereHas('product', fn ($p) => $p->where('slug', $filters['product_slug']))
            );
        }

        if ($filters['referrer_id']) {
            $query->whereHas('referral', fn ($q) => $q->where('referrer_id', $filters['referrer_id']));
        }

        match ($filters['sort']) {
            'name' => $query->orderBy('name'),
            'created_at' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('updated_at'),
        };

        $paginator = $query->paginate($filters['per_page'])->withQueryString();

        // Whole recurring revenue per customer (services + hosting + domains),
        // computed once for the page rather than per-row.
        $recurring = RecurringRevenue::compute();
        // Per-customer MRR contribution is an analytics figure (sprint step 11) —
        // redacted per row unless the user holds analytics.access. super_admin
        // passes via can(). Non-financial customer data stays.
        $canAnalytics = $request->user()->can('analytics.access');

        $paginator->through(function (Company $customer) use ($recurring, $canAnalytics): array {
            $products = $customer->customerProducts
                ->groupBy('product_id')
                ->map(function ($group): array {
                    /** @var CustomerProduct $first */
                    $first = $group->first();

                    return [
                        'slug' => $first->product?->slug,
                        'name' => $first->product?->name,
                        'icon_colour' => $first->product?->icon_colour,
                        'pb_class' => self::PRODUCT_SLUG_TO_PB[$first->product?->slug] ?? 'maa',
                        'count' => $group->count(),
                        'status' => $first->status,
                        'plan' => $first->plan,
                    ];
                })
                ->values()
                ->all();

            $mrr = $recurring->forCustomer($customer->id);

            $referrerUser = $customer->referral?->referrer?->user;

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'trading_name' => $customer->trading_name,
                'city' => $customer->city,
                'country' => $customer->country,
                'pipeline_stage' => $customer->pipeline_stage,
                // Asset-aware "Active" — true if the customer has ANY active
                // billable asset/service (service CP / hosting website / domain),
                // from the SAME source as MRR so the badge can't disagree with it.
                // Post "bill-from-the-asset" rework the old product-only check
                // read hosting/domain-only customers as Inactive.
                'is_active' => $recurring->isActiveCustomer($customer->id),
                'archived_at' => $customer->archived_at?->toIso8601String(),
                'primary_contact' => $customer->primaryContact
                    ? [
                        'name' => $customer->primaryContact->name,
                        'email' => $customer->primaryContact->email,
                    ]
                    : null,
                'products' => $products,
                'mrr' => $canAnalytics ? $mrr : null,
                'referrer' => $referrerUser
                    ? ['name' => $referrerUser->name]
                    : null,
                'created_at' => $customer->created_at?->toIso8601String(),
                'updated_at' => $customer->updated_at?->toIso8601String(),
            ];
        });

        // Active/inactive counts share the asset-aware source with the per-row
        // badge (RecurringRevenue), so the stat row can't disagree with the
        // badges or MRR. Trial stays a service-CP concept (assets have no trial).
        $activeIds = $recurring->activeCustomerIds();
        $summary = [
            'total' => Company::whereNull('archived_at')->count(),
            'active' => Company::whereNull('archived_at')->whereIn('id', $activeIds)->count(),
            'trial' => CustomerProduct::where('status', 'trial')->distinct('customer_id')->count('customer_id'),
            'inactive' => Company::whereNull('archived_at')
                ->whereNotIn('id', $activeIds)
                ->whereDoesntHave('customerProducts', fn ($q) => $q->where('status', 'trial'))
                ->count(),
        ];

        return Inertia::render('Internal/Customers/Index', [
            'customers' => $paginator,
            'filters' => $filters,
            'summary' => $summary,
            'products' => Product::orderBy('sort_order')->get(['id', 'slug', 'name']),
            'referrers' => Referrer::with('user:id,name')
                ->get()
                ->map(fn (Referrer $r) => [
                    'id' => $r->id,
                    'name' => $r->user?->name,
                ])
                ->values(),
            'pipeline_stages' => self::PIPELINE_STAGES,
            'types' => self::TYPE_VALUES,
            'contact_roles' => self::CONTACT_ROLES,
            'assignable_users' => User::whereIn('role', ['super_admin', 'staff'])
                ->orderBy('name')
                ->get(['id', 'name', 'role']),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $customer = Company::with([
            'contacts' => fn ($q) => $q->orderByDesc('is_primary')
                ->orderBy('name')
                ->with('portalUser:id,contact_id,email,last_login_at'),
            'assignedTo:id,name,role,avatar_colour',
            'customerProducts.product',
            'customerProducts.billingEntity:id,name',
            // planPrice eager-load — see note in index().
            'customerProducts.planPrice',
            'referral.referrer.user:id,name,role,avatar_colour',
            'notes' => fn ($q) => $q->with('createdBy:id,name,role,avatar_colour')
                ->orderByDesc('created_at')
                ->limit(10),
            // Load ALL activities for the timeline tab — completed +
            // open + notes — and order them so pinned/incomplete rise
            // to the top, completed sink to the bottom. The sidebar
            // card filters down to open ones client-side.
            // Company activity timeline: under Assigned the user sees only
            // their own activities on this account; None sees none; All/
            // super_admin see everything (phase 3b-ii — the eager load isn't
            // covered by Gate::before, so constrainRelation handles the bypass).
            'tasks' => function ($q) {
                $q->with(['contact:id,name', 'assignedTo:id,name'])
                    ->orderByRaw('is_pinned DESC, status = "complete", due_at IS NULL, due_at ASC, created_at DESC');
                ScopeEnforcer::constrainRelation($q, auth()->user(), ScopeArea::Tasks);
            },
            'domains',
            'invoices' => fn ($q) => $q->with('billingEntity:id,name')
                ->orderByDesc('created_at')
                ->limit(5),
            'groups.customers:id,name',
            // People (cross-company owners/associates) linked to this
            // company via customer_person. Pivot carries role + job_title.
            'people:id,name,email',
            'contracts' => fn ($q) => $q->orderByDesc('created_at')
                ->with('uploader:id,name'),
            // Projects belonging to this customer. Active first,
            // soonest-due first. Eager-counts let the card show
            // "5/12 tasks" without a per-row N+1.
            'projects' => fn ($q) => $q->whereNull('archived_at')
                ->withCount([
                    'tasks',
                    'tasks as completed_count' => fn ($q2) => $q2->where('status', 'complete'),
                ])
                ->orderByRaw("CASE status WHEN 'active' THEN 1 ELSE 2 END")
                ->orderByRaw('due_date IS NULL, due_date ASC'),
            'supportTickets:id,customer_id,subject,status',
            'portalUsers:id,customer_id,name,email,last_login_at,created_at',
            // Proposals tab — slim payload mapped below. Newest first
            // so the operator sees the most recent quote at a glance.
            'proposals' => fn ($q) => $q->orderByDesc('created_at'),
            // Websites tab — hosting/PageSpeed telemetry. The domain +
            // hosting-plan relations feed the SSL line and quota labels.
            'websites' => fn ($q) => $q->with([
                'domain:id,domain,ssl_status,expiry_date',
                // Hosting now lives on the website itself (Stage 1a).
                'plan:id,name,disk_quota_gb,email_quota,bandwidth_quota_gb',
                'planPrice:id,plan_id,price,interval_count,interval_unit,label',
            ])->orderBy('name'),
        ])->findOrFail($id);

        Gate::authorize('view', $customer);

        $totalSpend = (float) $customer->invoices()->where('status', 'paid')->sum('total');
        $openInvoiceCount = $customer->invoices()->whereIn('status', ['sent', 'overdue'])->count();
        $openTicketCount = $customer->supportTickets()
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        // Whole recurring revenue for this customer — services + hosting +
        // domains — via the canonical aggregator (interval-amortised).
        $mrr = RecurringRevenue::compute()->forCustomer($customer->id);

        $referrerUser = $customer->referral?->referrer?->user;
        $firstGroup = $customer->groups->first();

        $activity = ActivityLog::where('entity_type', 'customer')
            ->where('entity_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (ActivityLog $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'after' => $a->after,
                'before' => $a->before,
                'created_at' => $a->created_at?->toIso8601String(),
                'user_id' => $a->user_id,
            ]);

        return Inertia::render('Internal/Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'trading_name' => $customer->trading_name,
                'company_number' => $customer->company_number,
                'vat_number' => $customer->vat_number,
                'type' => $customer->type,
                'address_line1' => $customer->address_line1,
                'address_line2' => $customer->address_line2,
                'city' => $customer->city,
                'postcode' => $customer->postcode,
                'country' => $customer->country,
                'billing_address' => $customer->billing_address,
                'pipeline_stage' => $customer->pipeline_stage,
                // Acquisition channel — surface on the customer
                // header so staff can see at a glance how a lead
                // arrived. channel_detail is the free-text follow-up.
                'acquisition_channel' => $customer->acquisition_channel,
                'channel_detail' => $customer->channel_detail,
                'assigned_to' => $customer->assigned_to,
                'assigned_user' => $customer->assignedTo
                    ? [
                        'id' => $customer->assignedTo->id,
                        'name' => $customer->assignedTo->name,
                        'role' => $customer->assignedTo->role,
                        'avatar_colour' => $customer->assignedTo->avatar_colour,
                    ]
                    : null,
                'archived_at' => $customer->archived_at?->toIso8601String(),
                'created_at' => $customer->created_at?->toIso8601String(),
                // Auto-suspension exemption (super_admin toggle on the
                // overview tab).
                'exempt_from_auto_suspend' => $customer->exempt_from_auto_suspend,
                'exempt_reason' => $customer->exempt_reason,

                // GDPR state (super_admin GDPR panel). Formatted for
                // display; null until a request/erasure/export happens.
                'erasure_requested_at' => $customer->erasure_requested_at?->format('d M Y'),
                'erasure_completed_at' => $customer->erasure_completed_at?->format('d M Y'),
                'data_export_last_at' => $customer->data_export_last_at?->format('d M Y'),

                'contacts' => $customer->contacts->map(fn (Contact $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'email' => $c->email,
                    'phone' => $c->phone,
                    'job_title' => $c->job_title,
                    'role' => $c->role,
                    'is_primary' => (bool) $c->is_primary,
                    'notes' => $c->notes,
                    'has_portal_access' => $c->portalUser !== null,
                    'portal_email' => $c->portalUser?->email,
                    'portal_last_login' => $c->portalUser?->last_login_at?->diffForHumans(),
                    'portal_user_id' => $c->portalUser?->id,
                ])->values(),

                // People linked to this company (the "one person owns
                // many companies" layer). Role + job_title live on the
                // customer_person pivot.
                'people' => $customer->people->map(function (Person $p): array {
                    // Larastan can't type the belongsToMany ->using() pivot, so
                    // narrow it once here; the runtime access is correct.
                    /** @var CustomerPerson $pivot */
                    $pivot = $p->pivot; // @phpstan-ignore property.notFound

                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'email' => $p->email,
                        'role' => $pivot->role->value,
                        'role_label' => $pivot->role->label(),
                        'job_title' => $pivot->job_title,
                    ];
                })->values(),

                'products' => $customer->customerProducts->map(fn ($cp) => [
                    'id' => $cp->id,
                    'product_id' => $cp->product_id,
                    'slug' => $cp->product?->slug,
                    'name' => $cp->product?->name,
                    'icon_colour' => $cp->product?->icon_colour,
                    'pb_class' => self::PRODUCT_SLUG_TO_PB[$cp->product?->slug] ?? 'teal',
                    'status' => $cp->status,
                    'suspension_reason' => $cp->suspension_reason,
                    'suspended_at' => $cp->suspended_at?->toIso8601String(),
                    'suspended_by_system' => $cp->suspended_by_system,
                    // External-account provisioning (MyOrderPad). Null/ignored
                    // for products that don't provision an external account.
                    'provisionable' => in_array($cp->product?->slug, ['myorderpad', 'orderpad'], true),
                    'provision_status' => $cp->provision_status,
                    'provisioned_at' => $cp->provisioned_at?->diffForHumans(),
                    'external_email' => $cp->external_email,
                    'plan' => $cp->plan,
                    // Plan / price ids so the edit-service form can pre-select.
                    'plan_id' => $cp->plan_id,
                    'plan_price_id' => $cp->plan_price_id,
                    // Optional per-instance label — distinguishes multiple
                    // subscriptions of the same product.
                    'label' => $cp->label,
                    'price_monthly' => (float) ($cp->price_monthly ?? 0),
                    'interval_count' => $cp->interval_count,
                    'interval_unit' => $cp->interval_unit,
                    'interval_label' => $cp->interval_label,
                    'trial_ends_at' => $cp->trial_ends_at?->toIso8601String(),
                    'started_at' => $cp->started_at?->toIso8601String(),
                    'next_billing_date' => $cp->next_billing_date?->toIso8601String(),
                    'cancels_at' => $cp->cancels_at?->toIso8601String(),
                    'cancelled_at' => $cp->cancelled_at?->toIso8601String(),
                    'billing_entity' => $cp->billingEntity
                        ? ['id' => $cp->billingEntity->id, 'name' => $cp->billingEntity->name]
                        : null,
                ])->values(),

                // Hosting is now a property of the website, picked from the
                // catalog (see the top-level `hosting_plans` prop) — no longer
                // sourced from a pre-enabled CustomerProduct.

                // Per-customer MRR is an analytics figure (step 11) — redacted
                // unless analytics.access. total_spend (invoice history) stays.
                'mrr' => $request->user()->can('analytics.access') ? $mrr : null,
                'total_spend' => $totalSpend,
                'open_invoices' => $openInvoiceCount,
                'open_tickets' => $openTicketCount,

                // Billing P1: per-customer auto-collect intent + saved cards
                // (SAFE meta only — brand/last4/exp via the model accessor).
                'auto_collect' => (bool) $customer->auto_collect,
                'payment_methods' => PaymentMethod::where('customer_id', $customer->id)
                    ->where('status', 'active')
                    ->orderByDesc('is_default')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (PaymentMethod $pm): array => [
                        'id' => $pm->id,
                        'label' => $pm->label,
                        'is_default' => $pm->is_default,
                    ])->all(),

                'referrer' => $customer->referral && $referrerUser
                    ? [
                        'referrer_id' => $customer->referral->referrer_id,
                        'name' => $referrerUser->name,
                        'attributed_at' => $customer->referral->attributed_at?->toIso8601String(),
                    ]
                    : null,

                // Empty array when a referral is already attached — the
                // Vue side uses available_referrers.length === 0 + the
                // existing customer.referrer presence to decide which
                // sub-state of the Referral card to render. Only super_admin
                // can act on the list, but exposing it for everyone is
                // cheap and the policy gate fires server-side anyway.
                'available_referrers' => $customer->referral
                    ? []
                    : Referrer::where('is_active', true)
                        ->with('user:id,name,email')
                        ->get()
                        ->map(fn (Referrer $r): array => [
                            'id' => $r->id,
                            // Referrer.user is eager-loaded and the FK
                            // is NOT NULL — phpstan won't accept the
                            // nullsafe so we trust the relation here.
                            'name' => $r->user->name,
                            'email' => $r->user->email,
                        ])
                        ->values()
                        ->all(),

                'group' => $firstGroup
                    ? [
                        'id' => $firstGroup->id,
                        'name' => $firstGroup->name,
                        'member_count' => $firstGroup->customers->count(),
                    ]
                    : null,

                // All groups this customer currently belongs to — used
                // by the overview header to render coloured chips and
                // by the "Add to group" slide-over to know which
                // groups are already assigned.
                'customer_groups' => $customer->groups->map(fn (AccountGroup $g): array => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'colour' => $g->colour,
                ])->values()->all(),

                'notes' => $customer->notes->map(fn (Note $n) => [
                    'id' => $n->id,
                    'type' => $n->type,
                    'body' => $n->body,
                    'created_at' => $n->created_at?->toIso8601String(),
                    'creator' => $n->createdBy
                        ? [
                            'id' => $n->createdBy->id,
                            'name' => $n->createdBy->name,
                            'role' => $n->createdBy->role,
                            'avatar_colour' => $n->createdBy->avatar_colour,
                        ]
                        : null,
                ])->values(),

                'tasks' => $customer->tasks->map(fn (Task $t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'type' => $t->type,
                    'type_icon' => $t->type_icon,
                    'type_colour' => $t->type_colour,
                    'priority' => $t->priority,
                    'description' => $t->description,
                    'status' => $t->status,
                    'due_at' => $t->due_at?->toIso8601String(),
                    'completed_at' => $t->completed_at?->toIso8601String(),
                    'completed_at_human' => $t->completed_at?->diffForHumans(),
                    'outcome' => $t->outcome,
                    'duration_minutes' => $t->duration_minutes,
                    'is_pinned' => $t->is_pinned,
                    'is_overdue' => $t->is_overdue,
                    'contact_id' => $t->contact_id,
                    'contact_name' => $t->contact?->name,
                    'customer_id' => $t->customer_id,
                    'assigned_to' => $t->assigned_to,
                    'assigned_to_name' => $t->assignedTo?->name,
                    'created_by' => $t->created_by,
                ])->values(),

                'invoices' => $customer->invoices->map(fn ($i) => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'type' => $i->type,
                    'status' => $i->status,
                    'total' => (float) $i->total,
                    'issue_date' => $i->issue_date?->toDateString(),
                    'paid_at' => $i->paid_at?->toIso8601String(),
                    'billing_entity' => $i->billingEntity
                        ? ['name' => $i->billingEntity->name]
                        : null,
                ])->values(),

                'domains' => $customer->domains->map(fn ($d) => [
                    'id' => $d->id,
                    'domain' => $d->domain,
                    'expiry_date' => $d->expiry_date?->toDateString(),
                    'ssl_expiry_date' => $d->ssl_expiry_date?->toDateString(),
                    'is_in_cloudflare' => (bool) $d->is_in_cloudflare,
                    'status' => $this->domainStatus($d),
                    // Registrar exists on the model but is frequently NULL (no
                    // WHOIS sync populates it) — the table/modal render "—".
                    'registrar' => $d->registrar,
                    // Extra read-only fields for the details modal.
                    'ssl_status' => $d->ssl_status,
                    'auto_renew' => (bool) $d->auto_renew,
                    'is_proxied' => (bool) $d->is_proxied,
                    'nameservers' => $d->nameservers,
                    'hosting_provider' => $d->hosting_provider,
                    'registered_at' => $d->registered_at?->toDateString(),
                    'notes' => $d->notes,
                    'last_synced_at' => $d->last_synced_at?->diffForHumans(),
                ])->values(),

                'websites' => $customer->websites->map(fn (Website $w): array => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'url' => $w->url,
                    'status' => $w->status,
                    'health_status' => $w->health_status,

                    // Hosting plan — carried on the website itself (Stage 1a),
                    // sourced from the catalog, not via a CustomerProduct.
                    'plan_id' => $w->plan_id,
                    'plan_price_id' => $w->plan_price_id,
                    'plan_name' => $w->plan?->name,
                    'plan_price_label' => $w->planPrice?->display_label,
                    'hosting_status' => $w->hosting_status,
                    // Hosting billing controls (Stage 1b).
                    'hosting_auto_invoice' => $w->hosting_auto_invoice,
                    'hosting_next_billing_date' => $w->hosting_next_billing_date?->toDateString(),
                    'disk_quota_gb' => $w->plan?->disk_quota_gb,

                    // Usage
                    'disk_used_mb' => $w->disk_used_mb,
                    'disk_quota_mb' => $w->disk_quota_mb,
                    'disk_percent' => $w->disk_percent,
                    'email_accounts_count' => $w->email_accounts_count,
                    'email_accounts_quota' => $w->email_accounts_quota,
                    'bandwidth_used_mb' => $w->bandwidth_used_mb,
                    'usage_checked_at' => $w->usage_checked_at?->diffForHumans(),

                    // Performance
                    'pagespeed_mobile' => $w->pagespeed_mobile,
                    'pagespeed_desktop' => $w->pagespeed_desktop,
                    'pagespeed_grade' => $w->pagespeed_grade,
                    'pagespeed_lcp' => $w->pagespeed_lcp !== null ? (float) $w->pagespeed_lcp : null,
                    'pagespeed_cls' => $w->pagespeed_cls !== null ? (float) $w->pagespeed_cls : null,
                    'pagespeed_checked_at' => $w->pagespeed_checked_at?->diffForHumans(),
                    // Top improvement suggestions (Lighthouse opportunities)
                    // captured on the last check — rendered as a panel under
                    // the performance scores. Null until the first run.
                    'pagespeed_data' => $w->pagespeed_data,

                    // WordPress (MainWP)
                    'wp_version' => $w->wp_version,
                    'php_version' => $w->php_version,
                    'plugins_total' => $w->plugins_total,
                    'plugins_outdated' => $w->plugins_outdated,
                    'themes_outdated' => $w->themes_outdated,
                    'last_backup_at' => $w->last_backup_at?->diffForHumans(),
                    'mainwp_site_id' => $w->mainwp_site_id,

                    // Domain
                    'domain_id' => $w->domain_id,
                    'domain_name' => $w->domain?->domain,
                    'ssl_status' => $this->resolveWebsiteSsl($w),

                    // Connections / cPanel
                    'customer_product_id' => $w->customer_product_id,
                    'project_id' => $w->project_id,
                    'cpanel_username' => $w->cpanel_username,
                    'cpanel_server' => $w->cpanel_server,
                    'has_cpanel' => ! empty($w->cpanel_username),
                    'whm_managed' => $w->whm_managed,
                    'ga4_property_id' => $w->ga4_property_id,
                    'notes' => $w->notes,
                ])->values(),

                'contracts_count' => $customer->contracts->count(),
                'contracts' => $customer->contracts->map(fn (Contract $c): array => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'description' => $c->description,
                    'type' => $c->type,
                    'status' => $c->status,
                    'value' => $c->value !== null ? (float) $c->value : null,
                    'signed_at' => $c->signed_at?->format('d M Y'),
                    'start_date' => $c->start_date?->toDateString(),
                    'end_date' => $c->end_date?->toDateString(),
                    'end_date_display' => $c->end_date?->format('d M Y'),
                    'expires_in_days' => $c->expires_in_days,
                    'is_expired' => $c->is_expired,
                    'has_file' => $c->pdf_path !== null && $c->pdf_path !== '',
                    'original_name' => $c->file_original_name,
                    'notes' => $c->notes,
                    'uploader' => $c->uploader?->name,
                    'created_at' => $c->created_at?->format('d M Y'),
                ])->values(),

                // Origin lead — populated for customers minted via
                // LeadController::convert. Surfaces a "converted from
                // lead" chip on the overview tab and a link back to
                // the lead history. Returns the slim subset of fields
                // the chip actually uses.
                'lead_origin' => Lead::where('customer_id', $id)
                    ->orderByDesc('converted_at')
                    ->first()?->only([
                        'id', 'first_name', 'last_name',
                        'source', 'source_detail', 'estimated_value',
                        'converted_at',
                    ]),

                // Proposals tab — slim payload for the customer
                // detail page. Larastan can't infer the relation's
                // model type from the closure parameter (same issue
                // we hit with projects), so we ignore the typed-map
                // shape check. The accessed properties are all on
                // the Proposal model proper.
                /** @phpstan-ignore-next-line argument.type */
                'proposals' => $customer->proposals->map(fn (Proposal $p): array => [
                    'id' => $p->id,
                    'reference' => $p->reference,
                    'title' => $p->title,
                    'status' => $p->status,
                    'total' => (float) $p->total,
                    'sent_at' => $p->sent_at?->format('d M Y'),
                    'accepted_at' => $p->accepted_at?->format('d M Y'),
                    'created_at' => $p->created_at?->format('d M Y'),
                ])->values(),

                // Projects tab data — slim payload (compute progress
                // via the model accessor to share the same logic the
                // /projects index uses). Larastan hasn't picked up
                // Company::projects() since the relation was added
                // in this same change; the ignore lets the typed
                // closure compile until the next cache regenerate.
                /** @phpstan-ignore-next-line argument.type */
                'projects' => $customer->projects->map(fn (Project $p): array => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'status' => $p->status,
                    'priority' => $p->priority,
                    'colour' => $p->colour,
                    'progress' => $p->progress,
                    'due_date' => $p->due_date?->format('d M Y'),
                    'is_overdue' => $p->is_overdue,
                    'tasks_count' => $p->tasks_count ?? 0,
                    'completed_count' => $p->completed_count ?? 0,
                ])->values(),

                'portal_users' => $customer->portalUsers->map(fn (PortalUser $pu): array => [
                    'id' => $pu->id,
                    'name' => $pu->name,
                    'email' => $pu->email,
                    'last_login_at' => $pu->last_login_at?->diffForHumans(),
                    'created_at' => $pu->created_at?->toIso8601String(),
                ])->values(),

                'activity' => $activity,
            ],
            'users' => User::whereIn('role', ['super_admin', 'staff'])
                ->get(['id', 'name', 'role', 'avatar_colour']),
            'all_products' => Product::where('is_active', true)
                ->orWhere('is_coming_soon', true)
                ->orderBy('sort_order')
                ->get(['id', 'slug', 'name', 'icon_colour', 'is_coming_soon']),
            // Every active product — a customer can run several
            // subscriptions of the same product (e.g. one hosting plan
            // per website), so the Enable Product slide-over always
            // shows the full catalogue. Each product carries its
            // activePlans so the slide-over can render a plan picker and
            // auto-fill price; existing_counts (below) badges products
            // the customer already has so staff know they're adding a
            // new instance.
            'available_products' => Product::where('is_active', true)
                ->with([
                    'activePlans.activePrices',
                    'activePlans.category',
                    'planCategories.activePlans.activePrices',
                ])
                ->orderBy('sort_order')
                ->get(['id', 'slug', 'name', 'icon_colour'])
                ->map(function (Product $p): array {
                    // One mapper for plan + prices so the flat plans
                    // array, the categorised view, and uncategorised
                    // group all ship the same shape.
                    $mapPlan = fn (ProductPlan $plan, ?string $categoryName): array => [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'description' => $plan->description,
                        'category_id' => $plan->category_id,
                        'category_name' => $categoryName,
                        'features' => $plan->features ?? [],
                        'prices' => $plan->activePrices->map(fn (ProductPlanPrice $pp): array => [
                            'id' => $pp->id,
                            'price' => (float) $pp->price,
                            'interval_count' => $pp->interval_count,
                            'interval_unit' => $pp->interval_unit,
                            'interval_label' => $pp->interval_label,
                            'display_label' => $pp->display_label,
                            'label' => $pp->label,
                            'is_default' => $pp->is_default,
                        ])->values()->all(),
                    ];

                    return [
                        'id' => $p->id,
                        'slug' => $p->slug,
                        'name' => $p->name,
                        'icon_colour' => $p->icon_colour,
                        // Flat list kept for backward compatibility with
                        // selectedPlan() / selectedEnablePlan() lookups.
                        // Asset plans are excluded everywhere — a domain is added
                        // via the Domains section (Stage 1a) and hosting via a
                        // website (Stage 1b/3); the Services picker offers only
                        // genuine service plans.
                        'plans' => $p->activePlans
                            ->where('is_domain', false)
                            ->where('is_hosting', false)
                            ->map(fn (ProductPlan $plan): array => $mapPlan($plan, $plan->category?->name))
                            ->values()
                            ->all(),
                        'plan_categories' => $p->planCategories
                            ->map(fn (ProductPlanCategory $cat): array => [
                                'id' => $cat->id,
                                'name' => $cat->name,
                                'plans' => $cat->activePlans
                                    ->where('is_domain', false)
                                    ->where('is_hosting', false)
                                    ->map(fn (ProductPlan $plan): array => $mapPlan($plan, $cat->name))
                                    ->values()
                                    ->all(),
                            ])
                            ->values()
                            ->all(),
                        'uncategorised_plans' => $p->activePlans
                            ->whereNull('category_id')
                            ->where('is_domain', false)
                            ->where('is_hosting', false)
                            ->map(fn (ProductPlan $plan): array => $mapPlan($plan, null))
                            ->values()
                            ->all(),
                    ];
                })
                ->values(),
            // Catalog hosting plans for the website slide-over's hosting
            // picker (Stage 1a): active is_hosting plans + their active price
            // tiers. Sourced from the CATALOG — no pre-enabled product needed.
            'hosting_plans' => ProductPlan::where('is_hosting', true)
                ->where('is_active', true)
                ->with(['activePrices'])
                ->orderBy('name')
                ->get(['id', 'name', 'disk_quota_gb'])
                ->map(fn (ProductPlan $plan): array => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'disk_quota_gb' => $plan->disk_quota_gb,
                    'prices' => $plan->activePrices->map(fn (ProductPlanPrice $pp): array => [
                        'id' => $pp->id,
                        'label' => $pp->display_label,
                        'price' => (float) $pp->price,
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
            // How many live (active/trial) subscriptions the customer
            // already has per product — keyed product_id => count — so
            // the Enable Product picker can badge "(2 active)".
            'existing_counts' => CustomerProduct::where('customer_id', $customer->id)
                ->whereIn('status', ['active', 'trial'])
                ->selectRaw('product_id, COUNT(*) as aggregate')
                ->groupBy('product_id')
                ->pluck('aggregate', 'product_id'),
            'billing_entities' => BillingEntity::where('is_active', true)
                ->get(['id', 'name']),
            'pipeline_stages' => self::PIPELINE_STAGES,
            'contact_roles' => self::CONTACT_ROLES,
            // Person-role options for the People/Owners card's attach +
            // role-change controls. Sourced from the PersonRole enum so
            // there's a single source of truth.
            'person_roles' => PersonRole::options(),
            'note_types' => self::NOTE_TYPES,
            'types' => self::TYPE_VALUES,
            // Every customer group (segment) the operator could add
            // this customer to. Pre-fetched in full so the slide-over
            // doesn't need a round-trip on open.
            'available_groups' => AccountGroup::orderBy('name')
                ->get(['id', 'name', 'colour'])
                ->all(),
            // TLDs of active is_domain plans for the in-context "Add domain"
            // modal's TLD picker (same source + shape as DomainController@index).
            // A domain's TLD is the renewal price/term source; auto-renew only
            // bills when the TLD matches one of these active plans.
            'domain_tlds' => ProductPlan::where('is_domain', true)
                ->where('is_active', true)
                ->whereNotNull('tld')
                ->orderBy('tld')
                ->get(['id', 'tld', 'name'])
                ->map(fn (ProductPlan $p): array => [
                    'tld' => $p->tld,
                    'plan_name' => $p->name,
                ])
                ->all(),
        ]);
    }

    public function store(StoreCompanyRequest $request, CompanyProvisioningService $provisioner): RedirectResponse
    {
        $data = $request->validated();

        $customer = DB::transaction(function () use ($data, $request, $provisioner) {
            // Company + primary Contact + the people-layer link (Layer 1:
            // Person dedupe by email + customer_person pivot) are assembled
            // by CompanyProvisioningService — the same funnel
            // LeadController::convert routes through. Runs inside this
            // transaction: the Person resolve recovers from the
            // people.email UNIQUE race internally, so a throw here is a
            // genuine error that SHOULD roll the whole create back rather
            // than being silently swallowed into an unlinked contact.
            // Comms still read customer->primaryContact.
            $customer = $provisioner->provision(
                [
                    'name' => $data['name'],
                    'trading_name' => $data['trading_name'] ?? null,
                    'company_number' => $data['company_number'] ?? null,
                    'vat_number' => $data['vat_number'] ?? null,
                    'type' => $data['type'],
                    'address_line1' => $data['address_line1'],
                    'address_line2' => $data['address_line2'] ?? null,
                    'city' => $data['city'],
                    'postcode' => $data['postcode'],
                    'country' => $data['country'] ?? 'GB',
                    'pipeline_stage' => $data['pipeline_stage'] ?? 'lead',
                    'acquisition_channel' => $data['acquisition_channel'] ?? null,
                    'channel_detail' => $data['channel_detail'] ?? null,
                    'assigned_to' => $data['assigned_to'] ?? null,
                ],
                [
                    'name' => $data['contact_name'],
                    'email' => $data['contact_email'],
                    'phone' => $data['contact_phone'] ?? null,
                    'role' => $data['contact_role'] ?? 'owner',
                    'person_id' => $data['person_id'] ?? null,
                ],
                $request->user(),
            )->company;

            // Referral attribution — when the operator picked a
            // referrer at create time, write the pivot now so the
            // commission engine has something to compute against
            // from the customer's first invoice onward.
            if (! empty($data['referrer_id'])) {
                CustomerReferral::create([
                    'customer_id' => $customer->id,
                    'referrer_id' => $data['referrer_id'],
                    'attributed_at' => now(),
                ]);
            }

            $this->logActivity($request, 'customer.created', $customer, after: ['name' => $customer->name]);

            return $customer;
        });

        return redirect()
            ->route('internal.companies.show', $customer->id)
            ->with('success', "Created {$customer->name}");
    }

    public function update(int $id, UpdateCompanyRequest $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        $data = $request->validated();

        DB::transaction(function () use ($customer, $data, $request) {
            $before = $customer->only(array_keys($data));
            $customer->update($data);
            $after = $customer->fresh()->only(array_keys($data));

            $this->logActivity($request, 'customer.updated', $customer, before: $before, after: $after);
        });

        return redirect()
            ->route('internal.companies.show', $customer->id)
            ->with('success', 'Company updated.');
    }

    public function storeNote(int $id, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', self::NOTE_TYPES)],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($customer, $data, $request) {
            Note::create([
                'customer_id' => $customer->id,
                'created_by' => $request->user()->id,
                'type' => $data['type'],
                'body' => $data['body'],
            ]);

            $this->logActivity($request, 'customer.note_added', $customer, after: ['type' => $data['type']]);
        });

        return back()->with('success', 'Note added.');
    }

    public function storeTask(int $id, Request $request): RedirectResponse
    {
        // None scope (phase 3b-ii) is walled off Tasks entirely — no creating
        // an activity it could never see. All/super_admin pass.
        $this->authorizeScopeSection(ScopeArea::Tasks);
        // Capability (sprint step 8): creating a Task requires tasks.manage,
        // composing with the Tasks scope above + the customer update gate below.
        abort_unless($request->user()->can('tasks.manage'), 403, 'You do not have permission to manage tasks.');

        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            // This form only ever creates an actionable task (it posts no
            // type), so the shared rule requires a due date. Writes the
            // canonical due_at; the legacy due_date column is left untouched.
            'due_at' => TaskDueDate::rule(),
        ], TaskDueDate::messages());

        // resolve() bumps a bare YYYY-MM-DD to 09:00 so the slot reads like
        // a working day.
        $dueAt = TaskDueDate::resolve($data);

        DB::transaction(function () use ($customer, $data, $request, $dueAt) {
            Task::create([
                'customer_id' => $customer->id,
                'assigned_to' => $request->user()->id,
                'created_by' => $request->user()->id,
                'title' => $data['title'],
                // 'todo' is the post-PM equivalent of the old 'open'
                // — the entry state for the kanban workflow.
                'status' => 'todo',
                'due_at' => $dueAt,
            ]);

            $this->logActivity($request, 'customer.task_added', $customer, after: ['title' => $data['title']]);
        });

        return back()->with('success', 'Task added.');
    }

    public function archive(int $id, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        DB::transaction(function () use ($customer, $request) {
            $customer->update(['archived_at' => now()]);

            $this->logActivity($request, 'customer.archived', $customer, after: ['name' => $customer->name]);
        });

        return redirect()
            ->route('internal.companies.index')
            ->with('success', "Archived {$customer->name}");
    }

    public function enableProduct(int $id, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'plan_id' => ['nullable', 'integer', 'exists:product_plans,id'],
            'plan_price_id' => ['nullable', 'integer', 'exists:product_plan_prices,id'],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:365'],
            'interval_unit' => ['nullable', 'in:day,week,month,year,one_time'],
            'billing_entity_id' => ['nullable', 'integer', 'exists:billing_entities,id'],
            'plan' => ['nullable', 'string', 'max:100'],
            // Optional instance label — lets a customer run several
            // subscriptions of the same product, told apart by label.
            'label' => ['nullable', 'string', 'max:100'],
            'price_monthly' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'in:active,trial'],
            'trial_ends_at' => ['nullable', 'date', 'required_if:status,trial'],
        ]);

        // Multiple subscriptions of the same product are allowed (one
        // hosting plan per website, for instance), so there is no
        // double-enable guard — each submit creates a new instance.

        // Resolution order:
        //   1. plan_price_id wins for price + interval (canonical).
        //   2. plan_id wins for plan name.
        //   3. Free-text fallback for custom one-off arrangements.
        // The plan price ID also tells us the plan it belongs to, so
        // a payload that only includes plan_price_id still gets the
        // plan_id derived for the FK on customer_products.
        $planPrice = ! empty($data['plan_price_id']) ? ProductPlanPrice::find($data['plan_price_id']) : null;
        $plan = ! empty($data['plan_id'])
            ? ProductPlan::find($data['plan_id'])
            : ($planPrice ? ProductPlan::find($planPrice->plan_id) : null);

        // Assets are never CustomerProducts — block them here too so a crafted
        // request can't recreate one. Domains are added via the Domains section
        // (Stage 1a); hosting is added via a website (Stage 1b/3).
        if ($plan && $plan->is_domain) {
            throw ValidationException::withMessages([
                'plan_id' => 'Domains are managed as assets, not services.',
            ]);
        }
        if ($plan && $plan->is_hosting) {
            throw ValidationException::withMessages([
                'plan_id' => 'Hosting is managed on a website, not as a service.',
            ]);
        }

        $planName = $plan ? $plan->name : ($data['plan'] ?? null);
        $price = $planPrice
            ? (float) $planPrice->price
            : ($data['price_monthly'] ?? null);
        $intervalCount = $planPrice
            ? $planPrice->interval_count
            : (int) ($data['interval_count'] ?? 1);
        $intervalUnit = $planPrice
            ? $planPrice->interval_unit
            : ($data['interval_unit'] ?? 'month');

        DB::transaction(function () use ($customer, $data, $request, $plan, $planPrice, $planName, $price, $intervalCount, $intervalUnit) {
            CustomerProduct::create([
                'customer_id' => $customer->id,
                'product_id' => $data['product_id'],
                'plan_id' => $plan?->id,
                'plan_price_id' => $planPrice?->id,
                'label' => $data['label'] ?? null,
                'billing_entity_id' => $data['billing_entity_id'] ?? null,
                'plan' => $planName,
                'price_monthly' => $price,
                'interval_count' => $intervalCount,
                'interval_unit' => $intervalUnit,
                'status' => $data['status'],
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
                'started_at' => now(),
            ]);

            $this->logActivity($request, 'product.enabled', $customer, after: [
                'product_id' => $data['product_id'],
                'plan_id' => $plan?->id,
                'plan_price_id' => $planPrice?->id,
                'status' => $data['status'],
                'price' => $price,
                'interval_count' => $intervalCount,
                'interval_unit' => $intervalUnit,
            ]);
        });

        // MRR + total-customer counters can move on a new active sub.
        // nav.products is unaffected by customer-level subs but the
        // spec asked for it; keeping it lets future product-card counts
        // refresh on the next page load.
        Cache::forget('dash.mrr');
        Cache::forget('dash.total_customers');
        Cache::forget('nav.products');

        return back()->with('success', "Product enabled for {$customer->name}.");
    }

    public function suspendProduct(int $id, int $productId, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        $cp = CustomerProduct::where('customer_id', $id)
            ->where('id', $productId)
            ->firstOrFail();

        if ($cp->status === 'suspended' || $cp->status === 'cancelled') {
            return back()->with('error', 'This subscription is already inactive.');
        }

        DB::transaction(function () use ($cp, $customer, $request) {
            $before = ['status' => $cp->status];
            $cp->update([
                'status' => 'suspended',
                'cancelled_at' => now(),
            ]);

            $this->logActivity($request, 'product.suspended', $customer, $before, [
                'customer_product_id' => $cp->id,
                'status' => 'suspended',
            ]);
        });

        Cache::forget('dash.mrr');
        Cache::forget('dash.total_customers');

        return back()->with('success', 'Product subscription suspended.');
    }

    /**
     * Toggle whether this customer is exempt from the auto-suspension
     * sweep. super_admin only (gated on the route). The reason is kept
     * for the audit trail and surfaced in the customer header.
     */
    public function toggleExemption(int $id, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        $data = $request->validate([
            'exempt' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($customer, $data, $request): void {
            $before = ['exempt_from_auto_suspend' => $customer->exempt_from_auto_suspend];

            $customer->update([
                'exempt_from_auto_suspend' => $data['exempt'],
                // Clear the reason when removing the exemption.
                'exempt_reason' => $data['exempt'] ? ($data['reason'] ?? null) : null,
            ]);

            $this->logActivity($request, 'customer.suspension_exemption_changed', $customer, $before, [
                'exempt_from_auto_suspend' => $data['exempt'],
                'reason' => $data['exempt'] ? ($data['reason'] ?? null) : null,
            ]);
        });

        return back()->with('success', $data['exempt']
            ? 'Company exempted from auto-suspension.'
            : 'Auto-suspension exemption removed.');
    }

    /**
     * Toggle the per-customer auto-collect intent (Billing P1). Stored only —
     * nothing charges off-session until P2.
     */
    public function updateAutoCollect(int $id, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        $data = $request->validate([
            'auto_collect' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($customer, $data, $request): void {
            $before = ['auto_collect' => (bool) $customer->auto_collect];
            $customer->update(['auto_collect' => $data['auto_collect']]);
            $this->logActivity($request, 'customer.auto_collect_changed', $customer, $before, [
                'auto_collect' => $data['auto_collect'],
            ]);
        });

        return back()->with('success', 'Auto-collect '.($data['auto_collect'] ? 'enabled.' : 'disabled.'));
    }

    /**
     * Issue a PortalUser tied to a specific Contact. The contact must
     * belong to this customer (we re-scope to be sure) and must have
     * an email — without one there's nothing to invite. Re-inviting
     * the same contact rotates the password rather than creating a
     * second account.
     *
     * The temp password is flashed back to staff once and only once.
     * It's never logged, never returned by show(), and not stored
     * outside the hashed column.
     */
    public function inviteToPortal(int $id, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        $data = $request->validate([
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
        ]);

        // Re-scope the contact lookup to this customer so a forged
        // contact_id from another tenant can't be used as a vector.
        $contact = Contact::where('id', $data['contact_id'])
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        if (empty($contact->email)) {
            return back()->withErrors([
                'portal' => "{$contact->display_name} has no email on file. Add one before inviting them to the portal.",
            ]);
        }

        // Per-contact uniqueness: at most one portal account per contact.
        if (PortalUser::where('contact_id', $contact->id)->exists()) {
            return back()->withErrors([
                'portal' => "{$contact->display_name} already has portal access. Revoke it first to issue new credentials.",
            ]);
        }

        // Table-wide uniqueness on email: another contact (possibly on
        // a different customer) already owns this address.
        if (PortalUser::where('email', $contact->email)->exists()) {
            return back()->withErrors([
                'portal' => 'That email already has a portal account elsewhere. Use a different contact email.',
            ]);
        }

        $tempPassword = Str::password(14, symbols: false);

        $portalUser = DB::transaction(function () use ($customer, $contact, $tempPassword, $request): PortalUser {
            $portalUser = PortalUser::create([
                'customer_id' => $customer->id,
                'contact_id' => $contact->id,
                'name' => $contact->display_name,
                'email' => $contact->email,
                'password' => Hash::make($tempPassword),
            ]);

            ActivityLog::create([
                'user_id' => $request->user()?->id,
                'user_role' => $request->user()?->role,
                'action' => 'portal.invited',
                'entity_type' => Company::class,
                'entity_id' => $customer->id,
                'after' => [
                    'portal_user_id' => $portalUser->id,
                    'contact_id' => $contact->id,
                    'email' => $portalUser->email,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);

            return $portalUser;
        });

        // Email the invite + temp password to the contact. The flash
        // still carries the credentials so staff can also share them
        // out-of-band if email delivery is delayed. A mail-transport
        // failure must NOT 500 here — the portal user is already created
        // and the credentials are returned below; just report and move on.
        try {
            Mail::to($contact->email)->send(new PortalInvite($customer, $contact, $tempPassword));
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('portal_invite', [
            'login_url' => route('portal.login'),
            'email' => $portalUser->email,
            'password' => $tempPassword,
            'message' => "Portal invite emailed to {$contact->email}. Credentials are shown here too in case you'd rather share them through a trusted channel.",
        ]);
    }

    /**
     * Disables portal access. The PortalUser row stays so the audit
     * trail is intact, but its password is replaced with an
     * unrecoverable random string. If contact_id was set, the link
     * back to Contact is also broken so the contact can be deleted
     * cleanly afterwards.
     */
    public function revokePortalAccess(int $id, int $portalUserId, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        $portalUser = PortalUser::where('customer_id', $customer->id)
            ->findOrFail($portalUserId);

        $portalUser->forceFill([
            'password' => Hash::make(Str::random(40)),
            'contact_id' => null,
        ])->save();

        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => 'portal.access_revoked',
            'entity_type' => Company::class,
            'entity_id' => $customer->id,
            'after' => [
                'portal_user_id' => $portalUser->id,
                'email' => $portalUser->email,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', "Portal access revoked for {$portalUser->email}.");
    }

    /**
     * Tear down referral attribution. Sensitive enough that the route
     * is super_admin-only — removing a referrer breaks future commission
     * accruals on this customer, and silently voiding any pending
     * commissions is the kind of thing a regular staff member should
     * not be able to do unilaterally.
     *
     * Voided (not deleted) commissions: ledger entries are append-only
     * by design, and the staff-side reports rely on the trail of every
     * row that ever existed. Status='voided' takes them out of the
     * "owed to referrer" sum without destroying audit history.
     */
    /**
     * Attach a new referral to a customer. The route is super_admin-only
     * for the same reason removeReferral is — attribution flips future
     * commission accrual, so a casual mis-click would silently start
     * paying somebody. Only one referral per customer, enforced here
     * rather than via a DB unique because we want a friendly error
     * message rather than a 500.
     */
    public function addReferral(int $id, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        $user = $request->user();
        // Phase 3a: gated by companies.referral.manage (route also carries
        // permission:companies.referral.manage; this is defence-in-depth).
        abort_unless($user?->hasPermissionTo('companies.referral.manage') ?? false, 403, 'You do not have permission to attach a referral.');

        if (CustomerReferral::where('customer_id', $customer->id)->exists()) {
            return back()->with(
                'error',
                'This customer already has a referral attribution. Remove it first.',
            );
        }

        $data = $request->validate([
            'referrer_id' => ['required', 'integer', 'exists:referrers,id'],
            'attributed_at' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        DB::transaction(function () use ($customer, $data, $request): void {
            CustomerReferral::create([
                'customer_id' => $customer->id,
                'referrer_id' => $data['referrer_id'],
                'attributed_at' => $data['attributed_at'] ?? now(),
            ]);

            ActivityLog::create([
                'user_id' => $request->user()?->id,
                'user_role' => $request->user()?->role,
                'action' => 'customer.referral_added',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
                'after' => [
                    'referrer_id' => $data['referrer_id'],
                    'attributed_at' => $data['attributed_at'] ?? null,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        });

        return back()->with('success', 'Referral attribution added.');
    }

    public function removeReferral(int $id, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        $referral = CustomerReferral::where('customer_id', $customer->id)->first();
        if (! $referral) {
            return back()->withErrors([
                'referral' => 'This customer has no referral attribution.',
            ]);
        }

        $formerReferrerId = $referral->referrer_id;
        $pendingCommissions = (float) CommissionLedger::where('customer_id', $customer->id)
            ->where('referrer_id', $formerReferrerId)
            ->whereIn('status', ['pending', 'approved'])
            ->sum('commission_amount');

        DB::transaction(function () use ($customer, $referral, $formerReferrerId, $pendingCommissions, $request) {
            CommissionLedger::where('customer_id', $customer->id)
                ->where('referrer_id', $formerReferrerId)
                ->where('status', 'pending')
                ->update(['status' => 'voided', 'voided_reason' => 'referral_removed']);

            $referral->delete();

            ActivityLog::create([
                'user_id' => $request->user()?->id,
                'user_role' => $request->user()?->role,
                'action' => 'customer.referral_removed',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
                'after' => [
                    'former_referrer_id' => $formerReferrerId,
                    'pending_commissions_voided' => $pendingCommissions,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        });

        $msg = 'Referral attribution removed.';
        if ($pendingCommissions > 0) {
            $msg .= ' £'.number_format($pendingCommissions, 2).' of pending commissions voided.';
        }

        return back()->with('success', $msg);
    }

    private function logActivity(
        Request $request,
        string $action,
        Company $customer,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }

    private function domainStatus($d): string
    {
        if (! $d->is_in_cloudflare) {
            return 'external';
        }

        $expiry = $d->expiry_date;
        $ssl = $d->ssl_expiry_date;
        $today = now()->startOfDay();
        $soonest = collect([$expiry, $ssl])->filter()->min();

        if (! $soonest) {
            return 'healthy';
        }

        $days = $today->diffInDays($soonest, false);
        if ($days < 7) {
            return 'critical';
        }
        if ($days < 30) {
            return 'expiring';
        }

        return 'healthy';
    }

    /**
     * SSL state for a website card. A linked domain that has actually
     * been health-checked already knows its cert state — normalise that
     * vocabulary to the card's valid/expired language. Only when the
     * domain has never been checked (none/null) do we fall back to a
     * live TLS probe of the site, cached 6h so we don't reconnect on
     * every page load.
     */
    private function resolveWebsiteSsl(Website $w): string
    {
        $normalised = match ($w->domain?->ssl_status) {
            'active', 'expiring' => 'valid',
            'expired' => 'expired',
            default => null,
        };

        if ($normalised !== null) {
            return $normalised;
        }

        // 'unknown' (not null) is returned/cached when the probe can't
        // decide — Cache::remember re-runs its closure on a null hit, so
        // a null here would re-probe (5s) on every page load.
        return Cache::remember(
            "website.ssl.{$w->id}",
            now()->addHours(6),
            fn (): string => $this->checkSslDirect($w->url),
        );
    }

    /**
     * Open a TLS connection to the site and read the leaf certificate's
     * expiry. Returns 'valid' / 'expired', or 'unknown' when the host
     * can't be reached or presents no certificate. Best-effort and
     * bounded by a 5s timeout — never lets a dead host hang the request.
     */
    private function checkSslDirect(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return 'unknown';
        }

        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $socket = @stream_socket_client(
                'ssl://'.$host.':443',
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if ($socket === false) {
                return 'unknown';
            }

            $params = stream_context_get_params($socket);
            fclose($socket);

            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if ($cert === null) {
                return 'unknown';
            }

            $info = openssl_x509_parse($cert);
            if (! is_array($info) || ! isset($info['validTo_time_t'])) {
                return 'unknown';
            }

            return ((int) $info['validTo_time_t']) > now()->timestamp ? 'valid' : 'expired';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
