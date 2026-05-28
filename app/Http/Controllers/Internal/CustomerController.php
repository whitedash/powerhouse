<?php

namespace App\Http\Controllers\Internal;

use App\Events\PaginatedListAccessed;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\Referrer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    private const PIPELINE_STAGES = ['lead', 'prospect', 'active', 'churned'];

    private const TYPE_VALUES = ['restaurant', 'bar', 'bakery', 'cafe', 'venue', 'other'];

    private const CONTACT_ROLES = ['owner', 'manager', 'accounts', 'other'];

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

        $paginator->through(function (Customer $customer) {
            $products = $customer->customerProducts
                ->groupBy('product_id')
                ->map(function ($group) {
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
                ->values();

            $mrr = (float) $customer->customerProducts
                ->where('status', 'active')
                ->sum('price_monthly');

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
        Gate::authorize('view', Customer::class);

        return Inertia::render('Internal/Customers/Show', ['id' => $id]);
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

            ActivityLog::create([
                'user_id' => $request->user()?->id,
                'user_role' => $request->user()?->role,
                'action' => 'customer.created',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
                'after' => ['name' => $customer->name],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);

            return $customer;
        });

        return redirect()
            ->route('internal.customers.show', $customer->id)
            ->with('success', "Created {$customer->name}");
    }
}
