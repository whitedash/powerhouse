<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBillingEntityRequest;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Services\FileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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
                'default_vat_rate' => (float) $e->default_vat_rate,
                'vat_registered' => $e->vat_registered,
                'address' => $e->address,
                // Encrypted-cast fields decrypt automatically on read.
                // Never call Crypt::decrypt() — the model handles it.
                'bank_name' => $e->bank_name,
                'sort_code' => $e->sort_code,
                'account_number' => $e->account_number,
                'account_name' => $e->account_name,
                'logo_path' => $e->logo_path,
                // Signed temporary URL the Vue side can drop straight into
                // an <img> src. The 'serve' => true on the private disk
                // (config/filesystems.php) auto-registers a route that
                // validates the signature, so no extra controller needed.
                'logo_url' => $e->logo_path
                    ? Storage::disk('private')->temporaryUrl($e->logo_path, now()->addMinutes(30))
                    : null,
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

    public function uploadLogo(int $id, Request $request, FileUploadService $files): RedirectResponse
    {
        $entity = BillingEntity::findOrFail($id);
        Gate::authorize('update', $entity);

        // The 'logo' rule is a first-pass sanity check on size/MIME; the
        // service does the real validation (size, MIME-by-bytes, EXIF
        // strip, SVG sanitise) and throws ValidationException on failure.
        $request->validate([
            'logo' => ['required', 'file', 'mimes:jpeg,png,svg,webp', 'max:1024'],
        ]);

        DB::transaction(function () use ($entity, $request, $files) {
            $oldPath = $entity->logo_path;

            $path = $files->store($request->file('logo'), 'logo');

            $entity->update(['logo_path' => $path]);

            $this->logActivity($request, 'billing_entity.logo_uploaded', $entity, after: [
                'logo_path' => $path,
            ]);

            // Replace, not stack: the old file is unreachable now that the
            // model points at the new path, so delete it from disk. Done
            // after the new path is committed so a crash mid-flow doesn't
            // orphan the entity with a missing logo.
            if ($oldPath) {
                $files->delete($oldPath);
            }
        });

        return back()->with('success', 'Logo updated successfully.');
    }

    public function deleteLogo(int $id, Request $request, FileUploadService $files): RedirectResponse
    {
        $entity = BillingEntity::findOrFail($id);
        Gate::authorize('update', $entity);

        if (! $entity->logo_path) {
            return back();
        }

        DB::transaction(function () use ($entity, $request, $files) {
            $oldPath = $entity->logo_path;

            $entity->update(['logo_path' => null]);

            $this->logActivity($request, 'billing_entity.logo_removed', $entity, before: [
                'logo_path' => $oldPath,
            ]);

            $files->delete($oldPath);
        });

        return back()->with('success', 'Logo removed.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $entity = BillingEntity::findOrFail($id);
        Gate::authorize('delete', $entity);

        if ($entity->invoices()->exists()) {
            return back()->with(
                'error',
                'Cannot delete a billing entity that has invoices. Deactivate it instead.',
            );
        }

        $name = $entity->name;

        DB::transaction(function () use ($entity, $request, $name) {
            $this->logActivity($request, 'billing_entity.deleted', $entity, before: [
                'name' => $name,
            ]);

            $entity->delete();
        });

        return redirect()
            ->route('internal.settings.billing-entities.index')
            ->with('success', "{$name} deleted successfully.");
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
            'vat_number' => $data['vat_number'] ?? null,
            // VAT switch — when false, every document from this
            // entity renders without a VAT line. default_vat_rate
            // sticks around so toggling back on doesn't reset to 20%.
            'default_vat_rate' => $data['default_vat_rate'] ?? 20.00,
            'vat_registered' => (bool) ($data['vat_registered'] ?? true),
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
