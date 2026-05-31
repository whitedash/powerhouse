<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Website;
use App\Services\CpanelService;
use App\Services\PageSpeedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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

        return back()->with('success', 'Website added.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $website = Website::findOrFail($id);
        Gate::authorize('update', $website->customer);

        $data = $this->validateRow($request);

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

        return back()->with('success', 'Website removed.');
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
