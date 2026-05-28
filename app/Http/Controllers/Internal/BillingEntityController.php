<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBillingEntityRequest;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BillingEntityController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', BillingEntity::class);

        $entities = BillingEntity::orderBy('name')
            ->withCount('invoices')
            ->get()
            ->map(fn (BillingEntity $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'legal_name' => $e->legal_name,
                'company_number' => $e->company_number,
                'vat_number' => $e->vat_number,
                'address' => $e->address,
                // Encrypted-cast fields decrypt automatically on read.
                // Never call Crypt::decrypt() — the model handles it.
                'bank_name' => $e->bank_name,
                'sort_code' => $e->sort_code,
                'account_number' => $e->account_number,
                'account_name' => $e->account_name,
                'logo_path' => $e->logo_path,
                'postmark_sender_email' => $e->postmark_sender_email,
                'postmark_sender_name' => $e->postmark_sender_name,
                'postmark_domain' => $e->postmark_domain,
                'qbo_realm_id' => $e->qbo_realm_id,
                'is_active' => (bool) $e->is_active,
                'invoice_count' => (int) $e->invoices_count,
            ])
            ->values();

        $selectedId = $request->query('entity')
            ? (int) $request->query('entity')
            : ($entities->first()['id'] ?? null);

        return Inertia::render('Internal/Settings/BillingEntities', [
            'entities' => $entities,
            'selected_id' => $selectedId,
        ]);
    }

    public function store(StoreBillingEntityRequest $request): RedirectResponse
    {
        Gate::authorize('create', BillingEntity::class);

        $data = $request->validated();

        $entity = DB::transaction(function () use ($data, $request) {
            $entity = BillingEntity::create($this->mapPayload($data));

            $this->logActivity($request, 'billing_entity.created', $entity, after: [
                'name' => $entity->name,
            ]);

            return $entity;
        });

        return redirect()
            ->route('internal.settings.billing-entities.index', ['entity' => $entity->id])
            ->with('success', "{$entity->name} created successfully.");
    }

    public function update(int $id, StoreBillingEntityRequest $request): RedirectResponse
    {
        $entity = BillingEntity::findOrFail($id);
        Gate::authorize('update', $entity);

        $data = $request->validated();

        DB::transaction(function () use ($entity, $data, $request) {
            $entity->update($this->mapPayload($data));

            $this->logActivity($request, 'billing_entity.updated', $entity, after: [
                'name' => $entity->name,
            ]);
        });

        return back()->with('success', "{$entity->name} updated successfully.");
    }

    /**
     * Map flat validated payload to the column shape the model expects.
     * Address is passed as an array — the model's `'array'` cast handles
     * the JSON encoding. Encrypted fields are written as plaintext;
     * the encrypted cast wraps them on save.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapPayload(array $data): array
    {
        return [
            'name' => $data['name'],
            'legal_name' => $data['legal_name'],
            'company_number' => $data['company_number'],
            'vat_number' => $data['vat_number'],
            'address' => [
                'line1' => $data['address_line1'],
                'line2' => $data['address_line2'] ?? null,
                'city' => $data['city'],
                'postcode' => $data['postcode'],
                'country' => $data['country'],
            ],
            'bank_name' => $data['bank_name'],
            'sort_code' => $data['sort_code'],
            'account_number' => $data['account_number'],
            'account_name' => $data['account_name'],
            'postmark_sender_email' => $data['postmark_sender_email'],
            'postmark_sender_name' => $data['postmark_sender_name'],
            'postmark_domain' => $data['postmark_domain'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function logActivity(
        Request $request,
        string $action,
        BillingEntity $entity,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'billing_entity',
            'entity_id' => $entity->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
