<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Services\FileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CRUD for the Contracts tab on the customer detail page. The form
 * speaks the schema's vocabulary (status enum incl. signed/sent etc.
 * and end_date for expiry) rather than the simplified
 * draft/active/expired/terminated set — the richer enum is what
 * existing rows on the table already carry.
 */
class ContractController extends Controller
{
    private const TYPES = ['service_agreement', 'sow', 'retainer', 'nda', 'other'];

    private const STATUSES = ['draft', 'sent', 'signed', 'countersigned', 'expired', 'void'];

    public function store(Request $request, FileUploadService $uploads): RedirectResponse
    {
        $data = $this->validateRow($request, fileRequired: false);

        $customer = Customer::findOrFail($data['customer_id']);
        Gate::authorize('view', $customer);

        DB::transaction(function () use ($data, $request, $uploads): void {
            $payload = [
                'customer_id' => $data['customer_id'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'status' => $data['status'],
                'value' => $data['value'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'signed_at' => $data['signed_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ];

            if ($request->hasFile('file')) {
                $payload['pdf_path'] = $uploads->store($request->file('file'), 'contract');
                // Original filename is stored separately so the
                // download header can serve a sensible filename even
                // though the stored object is a uuid.
                $payload['file_original_name'] = $this->safeOriginalName($request->file('file')->getClientOriginalName());
            }

            $contract = Contract::create($payload);

            $this->log($request, 'contract.created', $contract->id, after: [
                'customer_id' => $contract->customer_id,
                'title' => $contract->title,
                'type' => $contract->type,
                'has_file' => $contract->pdf_path !== null,
            ]);
        });

        return back()->with('success', 'Contract added.');
    }

    public function update(int $id, Request $request, FileUploadService $uploads): RedirectResponse
    {
        $contract = Contract::findOrFail($id);
        $customer = Customer::findOrFail($contract->customer_id);
        Gate::authorize('view', $customer);

        $data = $this->validateRow($request, fileRequired: false, ignoreId: $id);

        DB::transaction(function () use ($contract, $data, $request, $uploads): void {
            $before = [
                'title' => $contract->title,
                'status' => $contract->status,
                'pdf_path' => $contract->pdf_path,
            ];

            $contract->fill([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'status' => $data['status'],
                'value' => $data['value'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'signed_at' => $data['signed_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($request->hasFile('file')) {
                // Replace the old file rather than orphaning it — the
                // storage path is uuid-generated so there's no name
                // collision risk.
                $oldPath = $contract->pdf_path;
                $contract->pdf_path = $uploads->store($request->file('file'), 'contract');
                $contract->file_original_name = $this->safeOriginalName($request->file('file')->getClientOriginalName());
                if ($oldPath !== null && $oldPath !== '') {
                    $uploads->delete($oldPath);
                }
            }

            $contract->save();

            $this->log($request, 'contract.updated', $contract->id, before: $before, after: [
                'title' => $contract->title,
                'status' => $contract->status,
                'has_file' => $contract->pdf_path !== null,
            ]);
        });

        return back()->with('success', 'Contract updated.');
    }

    public function destroy(int $id, Request $request, FileUploadService $uploads): RedirectResponse
    {
        $contract = Contract::findOrFail($id);
        $customer = Customer::findOrFail($contract->customer_id);
        Gate::authorize('view', $customer);

        DB::transaction(function () use ($contract, $request, $uploads): void {
            $snapshot = [
                'title' => $contract->title,
                'type' => $contract->type,
                'customer_id' => $contract->customer_id,
            ];

            if ($contract->pdf_path !== null && $contract->pdf_path !== '') {
                $uploads->delete($contract->pdf_path);
            }
            $contract->delete();

            $this->log($request, 'contract.deleted', $snapshot['customer_id'], before: $snapshot);
        });

        return back()->with('success', 'Contract removed.');
    }

    /**
     * Stream the stored PDF back to the operator with a friendly
     * filename. The download is gated through Customer view so a
     * leaked id can't be used to scrape PDFs.
     */
    public function download(int $id, Request $request): BinaryFileResponse|StreamedResponse
    {
        $contract = Contract::findOrFail($id);
        $customer = Customer::findOrFail($contract->customer_id);
        Gate::authorize('view', $customer);

        abort_unless($contract->pdf_path !== null && $contract->pdf_path !== '', 404, 'No file on this contract.');
        abort_unless(Storage::disk('private')->exists($contract->pdf_path), 404, 'Stored file is missing.');

        $this->log($request, 'contract.downloaded', $contract->id, after: [
            'customer_id' => $contract->customer_id,
        ]);

        $filename = $contract->file_original_name
            ?: ('contract-'.$contract->id.'.pdf');

        return Storage::disk('private')->download($contract->pdf_path, $filename);
    }

    /**
     * Shared validator. `file` is always optional — required-ness
     * is decided per call site since editing without a new file is
     * the common case.
     *
     * @return array<string, mixed>
     */
    private function validateRow(Request $request, bool $fileRequired = false, ?int $ignoreId = null): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(self::TYPES)],
            'status' => ['required', Rule::in(self::STATUSES)],
            // schema uses signed_at + end_date — the Vue form binds
            // its "expiry date" field to end_date so the operator
            // mental model lines up with the existing column.
            'signed_at' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // FileUploadService enforces the 20 MB ceiling + real
            // byte signature; this rule is a fast-fail at the
            // request boundary so the controller never even reaches
            // the service on an obvious mismatch.
            'file' => [$fileRequired ? 'required' : 'nullable', 'file', 'mimes:pdf', 'max:10240'],
        ]);
    }

    /**
     * Trim the original filename to something safe to echo back in
     * the download Content-Disposition. FileUploadService never
     * trusts client filenames for storage, so we only need to make
     * sure the header value can't carry a control character.
     */
    private function safeOriginalName(?string $name): string
    {
        $clean = preg_replace('/[\\x00-\\x1F\\x7F"]/', '', (string) $name) ?? '';
        $clean = trim($clean);
        if ($clean === '') {
            return 'contract.pdf';
        }

        return mb_substr($clean, 0, 255);
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
            'entity_type' => 'contract',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
