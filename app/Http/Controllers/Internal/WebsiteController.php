<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Domain;
use App\Models\ProductPlanPrice;
use App\Models\Project;
use App\Models\Website;
use App\Services\CpanelService;
use App\Services\MainWPService;
use App\Services\PageSpeedService;
use App\Services\WebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Websites — CRUD plus the two on-demand sync actions (cPanel usage,
 * PageSpeed). Lives on the customer detail Websites tab. Auth gates on
 * the owning customer's update policy so a site can only be touched by
 * someone who can edit its customer.
 */
class WebsiteController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateRow($request);

        $customer = Customer::findOrFail($data['customer_id']);
        Gate::authorize('update', $customer);

        $this->guardLinksBelongToCustomer($data);
        $data = $this->applyHostingState($data, null);

        DB::transaction(function () use ($data, $request): void {
            $website = Website::create([
                ...$data,
                'created_by' => $request->user()->id,
            ]);

            $this->log($request, 'website.created', $website->customer_id, after: [
                'website_id' => $website->id,
                'name' => $website->name,
                'url' => $website->url,
            ]);
        });

        // Hosting may contribute to MRR now — refresh the cached figure.
        Cache::forget('dash.mrr');

        return back()->with('success', 'Website added.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $website = Website::findOrFail($id);
        Gate::authorize('update', $website->customer);

        $data = $this->validateRow($request);

        $this->guardLinksBelongToCustomer($data);
        $data = $this->applyHostingState($data, $website);

        // The token is never sent back to the client (encrypted). On edit
        // a blank field means "keep the existing token" — drop it so we
        // don't overwrite a stored secret with null.
        if (empty($data['cpanel_token'])) {
            unset($data['cpanel_token']);
        }

        DB::transaction(function () use ($website, $data, $request): void {
            $before = $website->only(['name', 'url', 'status']);
            $website->update($data);

            $this->log($request, 'website.updated', $website->customer_id, $before, [
                'website_id' => $website->id,
                'name' => $website->name,
            ]);
        });

        Cache::forget('dash.mrr');

        return back()->with('success', 'Website updated.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $website = Website::findOrFail($id);
        Gate::authorize('update', $website->customer);

        DB::transaction(function () use ($website, $request): void {
            $snapshot = ['name' => $website->name, 'url' => $website->url];
            $customerId = $website->customer_id;
            $website->delete();
            $this->log($request, 'website.deleted', $customerId, before: $snapshot);
        });

        Cache::forget('dash.mrr');

        return back()->with('success', 'Website removed.');
    }

    /**
     * Suspend a website's hosting (Stage 1b): flips hosting_status to
     * suspended, which excludes it from the billing sweep, and fires the
     * website-hosting suspend webhook + WHM cascade keyed on the website.
     */
    public function suspendHosting(int $id, Request $request, WebhookDispatcher $dispatcher): RedirectResponse
    {
        $website = Website::findOrFail($id);
        Gate::authorize('update', $website->customer);

        if ($website->hosting_status !== 'active') {
            return back()->with('error', 'Hosting is not active for this website.');
        }

        DB::transaction(function () use ($website, $request): void {
            $website->update(['hosting_status' => 'suspended']);
            $this->log($request, 'website.hosting_suspended', $website->customer_id, after: [
                'website_id' => $website->id,
                'name' => $website->name,
            ]);
        });

        $dispatcher->dispatchHostingSuspension($website->fresh());

        // Suspended hosting drops out of MRR — refresh the cached figure.
        Cache::forget('dash.mrr');

        return back()->with('success', 'Hosting suspended.');
    }

    /**
     * Reinstate a website's hosting (Stage 1b): flips suspended -> active so
     * the billing sweep resumes, and fires the reinstate webhook + WHM cascade.
     */
    public function reinstateHosting(int $id, Request $request, WebhookDispatcher $dispatcher): RedirectResponse
    {
        $website = Website::findOrFail($id);
        Gate::authorize('update', $website->customer);

        if ($website->hosting_status !== 'suspended') {
            return back()->with('error', 'Hosting is not suspended for this website.');
        }

        DB::transaction(function () use ($website, $request): void {
            $website->update(['hosting_status' => 'active']);
            $this->log($request, 'website.hosting_reinstated', $website->customer_id, after: [
                'website_id' => $website->id,
                'name' => $website->name,
            ]);
        });

        $dispatcher->dispatchHostingReinstatement($website->fresh());

        // Reinstated hosting re-enters MRR — refresh the cached figure.
        Cache::forget('dash.mrr');

        return back()->with('success', 'Hosting reinstated.');
    }

    public function syncHosting(int $id): RedirectResponse
    {
        $website = Website::findOrFail($id);
        Gate::authorize('update', $website->customer);

        if (empty($website->cpanel_username) || empty($website->cpanel_token)) {
            return back()->with('error', 'No cPanel credentials configured for this website.');
        }

        try {
            $data = app(CpanelService::class, ['website' => $website])->syncAll();
            $website->update($data);

            return back()->with('success', 'Hosting data refreshed.');
        } catch (\Throwable $e) {
            return back()->with('error', 'cPanel sync failed: '.$e->getMessage());
        }
    }

    public function checkPageSpeed(int $id): RedirectResponse
    {
        $website = Website::findOrFail($id);
        Gate::authorize('update', $website->customer);

        try {
            $data = app(PageSpeedService::class)->check($website);
            $website->update($data);

            return back()->with('success', 'PageSpeed check complete. Score: '.$data['pagespeed_mobile'].'/100');
        } catch (\Throwable $e) {
            return back()->with('error', 'PageSpeed check failed: '.$e->getMessage());
        }
    }

    public function syncWordPress(int $id): RedirectResponse
    {
        $website = Website::findOrFail($id);
        Gate::authorize('update', $website->customer);

        if (! $website->mainwp_site_id) {
            return back()->with('error', 'This website is not linked to MainWP. Add the MainWP site ID to connect it.');
        }

        if (! config('services.mainwp.enabled')) {
            return back()->with('error', 'MainWP is not configured in Settings → Integrations.');
        }

        try {
            $mainwp = app(MainWPService::class);
            $data = $mainwp->getSite($website->mainwp_site_id);

            if (! $data) {
                return back()->with('error', 'Site not found in MainWP.');
            }

            $website->update($mainwp->mapSiteData($data));

            return back()->with('success', 'WordPress data updated.');
        } catch (\Throwable $e) {
            return back()->with('error', 'MainWP sync failed: '.$e->getMessage());
        }
    }

    /**
     * Derive the hosting lifecycle from the chosen plan (Stage 1a). A plan
     * present => active hosting (preserving an existing suspended state + the
     * original start timestamp); no plan => none. Also validates the chosen
     * tier is an active price of the chosen plan. No "enable product" step.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyHostingState(array $data, ?Website $website): array
    {
        $planId = $data['plan_id'] ?? null;

        if ($planId) {
            $priceId = $data['plan_price_id'] ?? null;
            if ($priceId !== null) {
                $valid = ProductPlanPrice::where('id', $priceId)
                    ->where('plan_id', $planId)
                    ->where('is_active', true)
                    ->exists();

                if (! $valid) {
                    throw ValidationException::withMessages([
                        'plan_price_id' => 'Selected billing tier is not an active price of the chosen hosting plan.',
                    ]);
                }
            }

            $data['plan_price_id'] = $priceId;
            // Don't re-activate a suspended site on a plain edit; keep its
            // original start date once set.
            $data['hosting_status'] = $website && $website->hosting_status === 'suspended' ? 'suspended' : 'active';
            $data['hosting_started_at'] = $website && $website->hosting_started_at !== null
                ? $website->hosting_started_at
                : now();
            // Billing anchors (Stage 1b): carry the toggle + next-billing date.
            $data['hosting_auto_invoice'] = (bool) ($data['hosting_auto_invoice'] ?? false);
            $data['hosting_next_billing_date'] = $data['hosting_next_billing_date'] ?? null;
        } else {
            $data['plan_id'] = null;
            $data['plan_price_id'] = null;
            $data['hosting_status'] = 'none';
            $data['hosting_started_at'] = null;
            // No plan = nothing to bill.
            $data['hosting_auto_invoice'] = false;
            $data['hosting_next_billing_date'] = null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRow(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:500'],
            'customer_product_id' => ['nullable', 'integer', 'exists:customer_products,id'],
            // Hosting carried on the website, sourced from the CATALOG (an
            // active is_hosting plan + one of its active price tiers). No
            // pre-enabled CustomerProduct required (Stage 1a decoupling).
            'plan_id' => ['nullable', 'integer', Rule::exists('product_plans', 'id')->where('is_hosting', true)],
            'plan_price_id' => ['nullable', 'integer', 'exists:product_plan_prices,id'],
            // Hosting billing controls (Stage 1b) — only meaningful when a
            // plan is attached; applyHostingState clears them otherwise.
            'hosting_auto_invoice' => ['nullable', 'boolean'],
            'hosting_next_billing_date' => ['nullable', 'date'],
            'domain_id' => ['nullable', 'integer', 'exists:domains,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'cpanel_username' => ['nullable', 'string', 'max:100'],
            'cpanel_token' => ['nullable', 'string', 'max:1000'],
            'cpanel_server' => ['nullable', 'string', 'max:255'],
            'whm_managed' => ['nullable', 'boolean'],
            'ga4_property_id' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * Block a forged domain_id / customer_product_id / project_id that points
     * at a row owned by a different customer. The Rule\Exists checks in
     * validateRow confirm each link exists at all; this confirms each one
     * belongs to the website's customer (null/absent links are allowed).
     * Mirrors TaskController::guardContactBelongsToCustomer, but raises a
     * per-field validation error so each select surfaces its own message.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardLinksBelongToCustomer(array $data): void
    {
        if (empty($data['customer_id'])) {
            return;
        }

        // field => [model, user-facing noun for the message]
        $links = [
            'domain_id' => [Domain::class, 'domain'],
            'customer_product_id' => [CustomerProduct::class, 'hosting plan'],
            'project_id' => [Project::class, 'project'],
        ];

        $errors = [];
        foreach ($links as $field => [$model, $label]) {
            if (empty($data[$field])) {
                continue;
            }

            $belongs = $model::where('id', $data[$field])
                ->where('customer_id', $data['customer_id'])
                ->exists();

            if (! $belongs) {
                $errors[$field] = "Selected {$label} does not belong to the chosen customer.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Request $request, string $action, int $customerId, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => $action,
            'entity_type' => 'website',
            'entity_id' => $after['website_id'] ?? 0,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
