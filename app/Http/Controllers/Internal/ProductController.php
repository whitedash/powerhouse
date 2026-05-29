<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Product::class);

        $products = Product::orderBy('sort_order')
            ->withCount([
                'customerProducts as active_customers' => fn ($q) => $q->where('status', 'active'),
                'customerProducts as total_customers',
            ])
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description,
                'icon_colour' => $p->icon_colour,
                'is_active' => $p->is_active,
                'is_coming_soon' => $p->is_coming_soon,
                'sort_order' => $p->sort_order,
                'active_customers' => (int) ($p->active_customers ?? 0),
                'total_customers' => (int) ($p->total_customers ?? 0),
            ])
            ->all();

        return Inertia::render('Internal/Settings/Products', [
            'products' => $products,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        $data = $this->validatePayload($request);

        $product = DB::transaction(function () use ($data, $request) {
            $product = Product::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'icon_colour' => $data['icon_colour'],
                'is_active' => $data['is_active'] ?? true,
                'is_coming_soon' => $data['is_coming_soon'] ?? false,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            $this->logActivity($request, 'product.created', $product, after: [
                'name' => $product->name,
                'slug' => $product->slug,
            ]);

            return $product;
        });

        return back()->with('success', "{$product->name} created.");
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $product = Product::findOrFail($id);
        Gate::authorize('update', $product);

        $data = $this->validatePayload($request, $product);

        // The slug is the public-facing identifier (URLs, API contracts,
        // billing-entity rule matching). Locking it once customers are
        // active prevents silent contract breakage downstream.
        $activeCustomers = $product->customerProducts()->where('status', 'active')->count();
        if ($activeCustomers > 0 && $data['slug'] !== $product->slug) {
            return back()->withErrors([
                'slug' => "Slug can't change while {$activeCustomers} customer".($activeCustomers === 1 ? '' : 's').' have an active subscription.',
            ])->withInput();
        }

        DB::transaction(function () use ($product, $data, $request) {
            $product->update([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'icon_colour' => $data['icon_colour'],
                'is_active' => $data['is_active'] ?? $product->is_active,
                'is_coming_soon' => $data['is_coming_soon'] ?? $product->is_coming_soon,
                'sort_order' => $data['sort_order'] ?? $product->sort_order,
            ]);

            $this->logActivity($request, 'product.updated', $product, after: [
                'name' => $product->name,
            ]);
        });

        return back()->with('success', "{$product->name} updated.");
    }

    public function toggleActive(int $id, Request $request): RedirectResponse
    {
        $product = Product::findOrFail($id);
        Gate::authorize('update', $product);

        // Deactivating with live customers would break their access
        // silently. Force the operator to suspend subscriptions first.
        if ($product->is_active && $product->customerProducts()->where('status', 'active')->exists()) {
            $count = $product->customerProducts()->where('status', 'active')->count();

            return back()->with(
                'error',
                "Cannot deactivate {$product->name} — it has {$count} active customer".($count === 1 ? '' : 's').'. Suspend their subscriptions first.',
            );
        }

        $newState = ! $product->is_active;

        DB::transaction(function () use ($product, $request, $newState) {
            $product->update([
                'is_active' => $newState,
                // Toggling active OFF and back ON should clear the
                // "coming soon" overlay — the product is shippable now.
                'is_coming_soon' => $newState ? false : $product->is_coming_soon,
            ]);

            $this->logActivity($request, 'product.toggled', $product, after: [
                'is_active' => $newState,
            ]);
        });

        $verb = $newState ? 'activated' : 'deactivated';

        return back()->with('success', "{$product->name} {$verb}.");
    }

    public function updateOrder(): RedirectResponse
    {
        Gate::authorize('viewAny', Product::class);

        // Drag-to-reorder ships in a later sprint. Returning here keeps
        // the route surface intact so the Vue side can wire its handler
        // before the server logic exists.
        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?Product $product = null): array
    {
        $slugRule = Rule::unique('products', 'slug');
        if ($product) {
            $slugRule = $slugRule->ignore($product->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:50', 'alpha_dash', $slugRule],
            'description' => ['nullable', 'string', 'max:500'],
            // Hex like #0D9488 — 7 chars including the #. The Vue picker
            // emits this shape; we re-validate so direct API hits can't
            // smuggle malformed colours into the rendered avatars.
            'icon_colour' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'is_coming_soon' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    private function logActivity(
        Request $request,
        string $action,
        Product $product,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
