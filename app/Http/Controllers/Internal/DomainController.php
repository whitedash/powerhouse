<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Domain;
use App\Services\CloudflareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    private const STATUSES = ['active', 'expiring_soon', 'expired', 'parked', 'transferred'];

    public function __construct(private readonly CloudflareService $cloudflare) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => in_array($request->query('status'), self::STATUSES, true)
                ? $request->query('status')
                : null,
        ];

        $paginator = Domain::query()
            ->with('customer:id,name')
            ->when(
                $filters['search'] !== '',
                fn ($q) => $q->where(function ($qq) use ($filters) {
                    $needle = '%'.$filters['search'].'%';
                    $qq->where('domain', 'like', $needle)
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $needle));
                }),
            )
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Domain $d): array => $this->mapDomain($d));

        $now = now();

        $summary = [
            'total' => Domain::count(),
            'expiring_30' => Domain::whereNotNull('expiry_date')
                ->where('expiry_date', '<=', $now->copy()->addDays(30))
                ->where('expiry_date', '>=', $now)
                ->count(),
            'expiring_7' => Domain::whereNotNull('expiry_date')
                ->where('expiry_date', '<=', $now->copy()->addDays(7))
                ->where('expiry_date', '>=', $now)
                ->count(),
            'expired' => Domain::whereNotNull('expiry_date')
                ->where('expiry_date', '<', $now)
                ->count(),
            'ssl_issues' => Domain::whereIn('ssl_status', ['expiring', 'expired'])->count(),
        ];

        return Inertia::render('Internal/Domains/Index', [
            'domains' => $paginator,
            'summary' => $summary,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            // Slim customer list for the add/edit slide-over's
            // searchable picker — active customers only.
            'customers' => Customer::whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name', 'city'])
                ->all(),
            'cloudflare_connected' => $this->cloudflare->testConnection(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $data = $this->validateRow($request);

        DB::transaction(function () use ($data, $request) {
            $domain = Domain::create($data + [
                'status' => 'active',
                'ssl_status' => 'none',
                'is_in_cloudflare' => ! empty($data['cloudflare_zone_id']),
            ]);

            if (! empty($data['cloudflare_zone_id'])) {
                $this->refreshDomainHealth($domain);
            }

            $this->log($request, 'domain.created', $domain->id, after: [
                'domain' => $domain->domain,
                'customer_id' => $domain->customer_id,
            ]);
        });

        return back()->with('success', 'Domain added.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $domain = Domain::findOrFail($id);
        Gate::authorize('viewAny', Customer::class);

        $data = $this->validateRow($request, $id);

        DB::transaction(function () use ($domain, $data, $request) {
            $before = [
                'domain' => $domain->domain,
                'expiry_date' => $domain->expiry_date?->toDateString(),
            ];
            $zoneChanged = ($data['cloudflare_zone_id'] ?? null) !== $domain->cloudflare_zone_id;

            $domain->fill($data + [
                'is_in_cloudflare' => ! empty($data['cloudflare_zone_id']),
            ])->save();

            if ($zoneChanged && ! empty($data['cloudflare_zone_id'])) {
                $this->refreshDomainHealth($domain);
            }

            $this->log($request, 'domain.updated', $domain->id, $before, [
                'domain' => $domain->domain,
                'expiry_date' => $domain->expiry_date?->toDateString(),
            ]);
        });

        return back()->with('success', 'Domain updated.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $domain = Domain::findOrFail($id);
        Gate::authorize('viewAny', Customer::class);

        DB::transaction(function () use ($domain, $request) {
            $snapshot = [
                'domain' => $domain->domain,
                'customer_id' => $domain->customer_id,
            ];
            $domain->delete();

            $this->log($request, 'domain.deleted', $snapshot['customer_id'], before: $snapshot);
        });

        return back()->with('success', 'Domain removed.');
    }

    /**
     * Manual health refresh — the daily artisan command does the
     * same thing across every domain at 06:00.
     */
    public function checkHealth(int $id, Request $request): RedirectResponse
    {
        $domain = Domain::findOrFail($id);
        Gate::authorize('viewAny', Customer::class);

        $this->refreshDomainHealth($domain);

        $this->log($request, 'domain.health_checked', $domain->id, after: [
            'status' => $domain->status,
            'ssl_status' => $domain->ssl_status,
        ]);

        return back()->with('success', 'Domain health refreshed.');
    }

    /**
     * DNS records for a Cloudflare-attached domain. Returns JSON
     * because the Vue side calls it via fetch — the records panel
     * is a slide-over that opens on demand from the ··· menu.
     */
    public function dnsRecords(int $id): JsonResponse
    {
        $domain = Domain::findOrFail($id);
        Gate::authorize('viewAny', Customer::class);

        if (empty($domain->cloudflare_zone_id)) {
            return response()->json(['records' => [], 'connected' => false]);
        }

        $records = $this->cloudflare->listDnsRecords($domain->cloudflare_zone_id);

        return response()->json([
            'connected' => true,
            'records' => array_map(fn (array $r): array => [
                'id' => (string) ($r['id'] ?? ''),
                'type' => (string) ($r['type'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'content' => (string) ($r['content'] ?? ''),
                'ttl' => (int) ($r['ttl'] ?? 0),
                'proxied' => (bool) ($r['proxied'] ?? false),
            ], $records),
        ]);
    }

    /**
     * Public so the artisan command can reuse the same refresh path
     * the controller's manual button uses. Writes ssl_expires_at +
     * ssl_status + last_synced_at + nameservers + is_proxied back to
     * the row, then recomputes status from the new ssl + expiry data.
     */
    public function refreshDomainHealth(Domain $domain): void
    {
        $health = $this->cloudflare->checkDomainHealth($domain);

        $domain->fill([
            'ssl_expiry_date' => $health['ssl_expires_at']?->toDateString(),
            'ssl_status' => $this->sslStatus($health['ssl_expires_at']),
            'nameservers' => $health['nameservers'] !== [] ? $health['nameservers'] : $domain->nameservers,
            'is_proxied' => $health['cloudflare_proxied'],
            'last_synced_at' => now(),
        ])->save();

        $domain->status = $this->domainStatus($domain);
        $domain->save();
    }

    private function sslStatus(?Carbon $expiry): string
    {
        if (! $expiry instanceof Carbon) {
            return 'none';
        }
        if ($expiry->isPast()) {
            return 'expired';
        }
        if ((int) now()->diffInDays($expiry) <= 30) {
            return 'expiring';
        }

        return 'active';
    }

    private function domainStatus(Domain $domain): string
    {
        if (! $domain->expiry_date) {
            return 'active';
        }
        if ($domain->expiry_date->isPast()) {
            return 'expired';
        }
        if ((int) now()->diffInDays($domain->expiry_date) <= 30) {
            return 'expiring_soon';
        }

        return 'active';
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDomain(Domain $domain): array
    {
        $now = now()->startOfDay();
        $daysUntilExpiry = $domain->expiry_date
            ? (int) $now->diffInDays($domain->expiry_date->copy()->startOfDay(), false)
            : null;

        return [
            'id' => $domain->id,
            'domain' => $domain->domain,
            'customer_id' => $domain->customer_id,
            'customer_name' => $domain->customer?->name,
            'registrar' => $domain->registrar,
            'registered_at' => $domain->registered_at?->toDateString(),
            'expiry_date' => $domain->expiry_date?->toDateString(),
            'expiry_date_display' => $domain->expiry_date?->format('d M Y'),
            'days_until_expiry' => $daysUntilExpiry,
            'auto_renew' => (bool) $domain->auto_renew,
            'status' => $domain->status,
            'ssl_expiry_date' => $domain->ssl_expiry_date?->toDateString(),
            'ssl_expiry_display' => $domain->ssl_expiry_date?->format('d M Y'),
            'ssl_status' => $domain->ssl_status,
            'cloudflare_zone_id' => $domain->cloudflare_zone_id,
            'has_cloudflare' => $domain->cloudflare_zone_id !== null && $domain->cloudflare_zone_id !== '',
            'is_proxied' => (bool) $domain->is_proxied,
            'nameservers' => $domain->nameservers ?? [],
            'last_synced_at' => $domain->last_synced_at?->diffForHumans(),
            'notes' => $domain->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRow(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^([a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
                'unique:domains,domain'.($ignoreId ? ",{$ignoreId}" : ''),
            ],
            'registrar' => ['nullable', 'string', 'max:100'],
            'registered_at' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'auto_renew' => ['nullable', 'boolean'],
            'cloudflare_zone_id' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Request $request, string $action, int $entityId, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'domain',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
