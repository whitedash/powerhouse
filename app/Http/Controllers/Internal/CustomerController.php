<?php

namespace App\Http\Controllers\Internal;

use App\Events\PaginatedListAccessed;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Note;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\Referrer;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
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
        Gate::authorize('viewAny', Customer::class);

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
            'per_page' => (int) ($request->query('per_page') ?: 20),
        ];

        $query = Customer::query()
            ->with([
                'primaryContact:id,customer_id,name,email',
                'customerProducts.product:id,slug,name,icon_colour',
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

        $paginator->through(function (Customer $customer): array {
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

            // mrr_contribution is a model accessor that respects
            // interval_count + interval_unit, so a quarterly sub at
            // £75 reports £25/mo instead of inflating MRR with the
            // full bill.
            $mrr = (float) $customer->customerProducts
                ->where('status', 'active')
                ->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution);

            $referrerUser = $customer->referral?->referrer?->user;

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'trading_name' => $customer->trading_name,
                'city' => $customer->city,
                'country' => $customer->country,
                'pipeline_stage' => $customer->pipeline_stage,
                'archived_at' => $customer->archived_at?->toIso8601String(),
                'primary_contact' => $customer->primaryContact
                    ? [
                        'name' => $customer->primaryContact->name,
                        'email' => $customer->primaryContact->email,
                    ]
                    : null,
                'products' => $products,
                'mrr' => $mrr,
                'referrer' => $referrerUser
                    ? ['name' => $referrerUser->name]
                    : null,
                'created_at' => $customer->created_at?->toIso8601String(),
                'updated_at' => $customer->updated_at?->toIso8601String(),
            ];
        });

        $summary = [
            'total' => Customer::whereNull('archived_at')->count(),
            'active' => CustomerProduct::where('status', 'active')->distinct('customer_id')->count('customer_id'),
            'trial' => CustomerProduct::where('status', 'trial')->distinct('customer_id')->count('customer_id'),
            'inactive' => Customer::whereNull('archived_at')
                ->whereDoesntHave(
                    'customerProducts',
                    fn ($q) => $q->whereIn('status', ['active', 'trial'])
                )
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

    public function show(int $id): Response
    {
        $customer = Customer::with([
            'contacts',
            'assignedTo:id,name,role,avatar_colour',
            'customerProducts.product',
            'customerProducts.billingEntity:id,name',
            'referral.referrer.user:id,name,role,avatar_colour',
            'notes' => fn ($q) => $q->with('createdBy:id,name,role,avatar_colour')
                ->orderByDesc('created_at')
                ->limit(10),
            'tasks' => fn ($q) => $q->where('status', 'open')->orderBy('due_date'),
            'domains',
            'invoices' => fn ($q) => $q->with('billingEntity:id,name')
                ->orderByDesc('created_at')
                ->limit(5),
            'groups.customers:id,name',
            'contracts:id,customer_id,title,status,type',
            'supportTickets:id,customer_id,subject,status',
        ])->findOrFail($id);

        Gate::authorize('view', $customer);

        $totalSpend = (float) $customer->invoices()->where('status', 'paid')->sum('total');
        $openInvoiceCount = $customer->invoices()->whereIn('status', ['sent', 'overdue'])->count();
        $openTicketCount = $customer->supportTickets()
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        // Same MRR-respects-interval rule as the list page — sum via
        // the accessor so a quarterly sub at £75 reports £25/mo.
        $mrr = (float) $customer->customerProducts
            ->where('status', 'active')
            ->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution);

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

                'contacts' => $customer->contacts->map(fn (Contact $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'email' => $c->email,
                    'phone' => $c->phone,
                    'role' => $c->role,
                    'is_primary' => (bool) $c->is_primary,
                ])->values(),

                'products' => $customer->customerProducts->map(fn ($cp) => [
                    'id' => $cp->id,
                    'product_id' => $cp->product_id,
                    'slug' => $cp->product?->slug,
                    'name' => $cp->product?->name,
                    'icon_colour' => $cp->product?->icon_colour,
                    'pb_class' => self::PRODUCT_SLUG_TO_PB[$cp->product?->slug] ?? 'teal',
                    'status' => $cp->status,
                    'plan' => $cp->plan,
                    'price_monthly' => (float) ($cp->price_monthly ?? 0),
                    'interval_count' => $cp->interval_count,
                    'interval_unit' => $cp->interval_unit,
                    'interval_label' => $cp->interval_label,
                    'trial_ends_at' => $cp->trial_ends_at?->toIso8601String(),
                    'started_at' => $cp->started_at?->toIso8601String(),
                    'cancelled_at' => $cp->cancelled_at?->toIso8601String(),
                    'billing_entity' => $cp->billingEntity
                        ? ['id' => $cp->billingEntity->id, 'name' => $cp->billingEntity->name]
                        : null,
                ])->values(),

                'mrr' => $mrr,
                'total_spend' => $totalSpend,
                'open_invoices' => $openInvoiceCount,
                'open_tickets' => $openTicketCount,

                'referrer' => $customer->referral && $referrerUser
                    ? [
                        'referrer_id' => $customer->referral->referrer_id,
                        'name' => $referrerUser->name,
                        'attributed_at' => $customer->referral->attributed_at?->toIso8601String(),
                    ]
                    : null,

                'group' => $firstGroup
                    ? [
                        'id' => $firstGroup->id,
                        'name' => $firstGroup->name,
                        'member_count' => $firstGroup->customers->count(),
                    ]
                    : null,

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
                    'status' => $t->status,
                    'due_date' => $t->due_date?->toDateString(),
                    'customer_id' => $t->customer_id,
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
                ])->values(),

                'contracts_count' => $customer->contracts->count(),

                'activity' => $activity,
            ],
            'users' => User::whereIn('role', ['super_admin', 'staff'])
                ->get(['id', 'name', 'role', 'avatar_colour']),
            'all_products' => Product::where('is_active', true)
                ->orWhere('is_coming_soon', true)
                ->orderBy('sort_order')
                ->get(['id', 'slug', 'name', 'icon_colour', 'is_coming_soon']),
            // Active products this customer does NOT already have on an
            // active/trial subscription — the Enable Product slide-over
            // should not surface a product that's already running. Each
            // product carries its activePlans so the slide-over can
            // render a plan picker and auto-fill price.
            'available_products' => Product::where('is_active', true)
                ->whereNotIn('id', $customer->customerProducts
                    ->whereIn('status', ['active', 'trial'])
                    ->pluck('product_id'))
                ->with(['activePlans.activePrices'])
                ->orderBy('sort_order')
                ->get(['id', 'slug', 'name', 'icon_colour'])
                ->map(fn (Product $p): array => [
                    'id' => $p->id,
                    'slug' => $p->slug,
                    'name' => $p->name,
                    'icon_colour' => $p->icon_colour,
                    'plans' => $p->activePlans->map(fn (ProductPlan $plan): array => [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'description' => $plan->description,
                        'category_id' => $plan->category_id,
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
                    ])->values()->all(),
                ])
                ->values(),
            'billing_entities' => BillingEntity::where('is_active', true)
                ->get(['id', 'name']),
            'pipeline_stages' => self::PIPELINE_STAGES,
            'contact_roles' => self::CONTACT_ROLES,
            'note_types' => self::NOTE_TYPES,
            'types' => self::TYPE_VALUES,
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $customer = DB::transaction(function () use ($data, $request) {
            $customer = Customer::create([
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
                'assigned_to' => $data['assigned_to'] ?? null,
            ]);

            Contact::create([
                'customer_id' => $customer->id,
                'name' => $data['contact_name'],
                'email' => $data['contact_email'],
                'phone' => $data['contact_phone'] ?? null,
                'role' => $data['contact_role'] ?? 'owner',
                'is_primary' => true,
            ]);

            $this->logActivity($request, 'customer.created', $customer, after: ['name' => $customer->name]);

            return $customer;
        });

        return redirect()
            ->route('internal.customers.show', $customer->id)
            ->with('success', "Created {$customer->name}");
    }

    public function update(int $id, UpdateCustomerRequest $request): RedirectResponse
    {
        $customer = Customer::findOrFail($id);
        Gate::authorize('update', $customer);

        $data = $request->validated();

        DB::transaction(function () use ($customer, $data, $request) {
            $before = $customer->only(array_keys($data));
            $customer->update($data);
            $after = $customer->fresh()->only(array_keys($data));

            $this->logActivity($request, 'customer.updated', $customer, before: $before, after: $after);
        });

        return redirect()
            ->route('internal.customers.show', $customer->id)
            ->with('success', 'Customer updated.');
    }

    public function storeNote(int $id, Request $request): RedirectResponse
    {
        $customer = Customer::findOrFail($id);
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
        $customer = Customer::findOrFail($id);
        Gate::authorize('update', $customer);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'due_date' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($customer, $data, $request) {
            Task::create([
                'customer_id' => $customer->id,
                'assigned_to' => $request->user()->id,
                'created_by' => $request->user()->id,
                'title' => $data['title'],
                'status' => 'open',
                'due_date' => $data['due_date'] ?? null,
            ]);

            $this->logActivity($request, 'customer.task_added', $customer, after: ['title' => $data['title']]);
        });

        return back()->with('success', 'Task added.');
    }

    public function archive(int $id, Request $request): RedirectResponse
    {
        $customer = Customer::findOrFail($id);
        Gate::authorize('update', $customer);

        DB::transaction(function () use ($customer, $request) {
            $customer->update(['archived_at' => now()]);

            $this->logActivity($request, 'customer.archived', $customer, after: ['name' => $customer->name]);
        });

        return redirect()
            ->route('internal.customers.index')
            ->with('success', "Archived {$customer->name}");
    }

    public function enableProduct(int $id, Request $request): RedirectResponse
    {
        $customer = Customer::findOrFail($id);
        Gate::authorize('update', $customer);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'plan_id' => ['nullable', 'integer', 'exists:product_plans,id'],
            'plan_price_id' => ['nullable', 'integer', 'exists:product_plan_prices,id'],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:365'],
            'interval_unit' => ['nullable', 'in:day,week,month,year,one_time'],
            'billing_entity_id' => ['nullable', 'integer', 'exists:billing_entities,id'],
            'plan' => ['nullable', 'string', 'max:100'],
            'price_monthly' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'in:active,trial'],
            'trial_ends_at' => ['nullable', 'date', 'required_if:status,trial'],
        ]);

        // Block double-enable — a customer can't run two active/trial
        // subscriptions for the same product simultaneously. The product
        // picker already filters this server-side, but the API is the
        // authoritative boundary.
        $alreadyActive = $customer->customerProducts()
            ->where('product_id', $data['product_id'])
            ->whereIn('status', ['active', 'trial'])
            ->exists();
        if ($alreadyActive) {
            return back()->with('error', 'This product is already enabled for this customer.');
        }

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
        $customer = Customer::findOrFail($id);
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

    private function logActivity(
        Request $request,
        string $action,
        Customer $customer,
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
}
