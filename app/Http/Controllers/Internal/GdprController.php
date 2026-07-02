<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\PortalUser;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * GDPR tooling — right to erasure (Article 17) and right to data
 * portability (Article 20). Erasure anonymises personal data in place
 * rather than deleting records, so financial/legal obligations (invoices)
 * survive. All three actions are super_admin-only (route middleware) and
 * additionally policy-checked here.
 *
 * NOTE on schema: the customers table holds no email/phone/notes columns —
 * individual PII lives on contacts and portal_users. The customer record's
 * own identifying fields (name, trading name, address, billing address,
 * acquisition detail) are the ones anonymised here.
 */
class GdprController extends Controller
{
    private const UNPAID_STATUSES = ['sent', 'overdue', 'partially_paid'];

    public function requestErasure(int $id, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        if ($customer->erasure_completed_at !== null) {
            return back()->with('error', 'This customer has already been erased.');
        }

        $customer->update([
            'erasure_requested_at' => now(),
            'erasure_requested_by' => $request->user()->id,
        ]);

        $this->logActivity($request, 'gdpr.erasure_requested', $customer);

        return back()->with('success', 'Erasure request logged. Complete erasure when legally permitted (typically after all invoices are settled).');
    }

    public function processErasure(int $id, Request $request): RedirectResponse
    {
        $customer = Company::findOrFail($id);
        Gate::authorize('update', $customer);

        if ($customer->erasure_completed_at !== null) {
            return back()->with('error', 'This customer has already been erased.');
        }

        // Financial records must be retained — block erasure while any
        // invoice is still owed.
        $unpaid = $customer->invoices()
            ->whereIn('status', self::UNPAID_STATUSES)
            ->count();

        if ($unpaid > 0) {
            return back()->with('error', "Cannot erase customer with {$unpaid} unpaid invoice(s). Settle all invoices first.");
        }

        DB::transaction(function () use ($customer, $request): void {
            $ref = 'ERASED-'.$customer->id;

            // Anonymise the customer's own identifying fields. Business
            // identifiers (company/VAT number) are left intact — they're
            // not personal data and tie the retained invoices to records.
            $customer->update([
                'name' => $ref,
                'trading_name' => null,
                'address_line1' => null,
                'address_line2' => null,
                'city' => null,
                'postcode' => null,
                'country' => null,
                'billing_address' => null,
                'channel_detail' => null,
                'erasure_completed_at' => now(),
            ]);

            // Anonymise contacts (the real home of individual PII).
            $customer->contacts()->update([
                'name' => $ref,
                'email' => null,
                'phone' => null,
                'job_title' => null,
                'notes' => null,
            ]);

            // Free-text personal data: notes and activities (tasks). The
            // spec's "activities" relation is `tasks` in this codebase.
            $customer->notes()->delete();
            $customer->tasks()->delete();

            // Portal access: revoke each portal user's OAuth tokens via the
            // Passport relation (correctly scoped to that user), then remove
            // the portal accounts entirely.
            $customer->portalUsers->each(function (PortalUser $pu): void {
                $pu->tokens()->update(['revoked' => true]);
            });
            $customer->portalUsers()->delete();

            $this->logActivity($request, 'gdpr.erasure_completed', $customer, after: [
                'reference' => $ref,
            ]);
        });

        return back()->with('success', 'Company data erased.');
    }

    public function exportData(int $id, Request $request): JsonResponse
    {
        $customer = Company::with([
            'contacts',
            'invoices.lines',
            'customerProducts.product',
            'customerProducts.productPlan',
            'supportTickets',
        ])->findOrFail($id);
        Gate::authorize('view', $customer);

        $export = [
            'exported_at' => now()->toISOString(),
            'exported_by' => $request->user()->name,
            'subject' => 'Data export for: '.$customer->name,
            // Individual email/phone live on contacts, not the customer
            // record, so they're surfaced in the contacts block below.
            'customer' => [
                'name' => $customer->name,
                'trading_name' => $customer->trading_name,
                'company_number' => $customer->company_number,
                'vat_number' => $customer->vat_number,
                'address_line1' => $customer->address_line1,
                'address_line2' => $customer->address_line2,
                'city' => $customer->city,
                'postcode' => $customer->postcode,
                'country' => $customer->country,
                'created_at' => $customer->created_at?->toDateString(),
            ],
            'contacts' => $customer->contacts->map(fn (Contact $c): array => [
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'job_title' => $c->job_title,
            ])->values(),
            'invoices' => $customer->invoices->map(fn (Invoice $i): array => [
                'number' => $i->number,
                'total' => (float) $i->total,
                'status' => $i->status,
                'issued' => $i->issue_date?->toDateString(),
                'due' => $i->due_date?->toDateString(),
                'lines' => $i->lines->map(fn ($l): array => [
                    'description' => $l->description,
                    'quantity' => $l->quantity,
                    'unit_price' => (float) $l->unit_price,
                    'amount' => (float) $l->amount,
                ])->values()->all(),
            ])->values(),
            'subscriptions' => $customer->customerProducts->map(fn (CustomerProduct $cp): array => [
                'product' => $cp->product?->name,
                'plan' => $cp->productPlan?->name,
                'status' => $cp->status,
                'since' => $cp->created_at?->toDateString(),
            ])->values(),
            'support_tickets' => $customer->supportTickets->map(fn (SupportTicket $t): array => [
                'id' => $t->id,
                'subject' => $t->subject,
                'status' => $t->status,
                'created_at' => $t->created_at?->toDateString(),
            ])->values(),
        ];

        $customer->update(['data_export_last_at' => now()]);

        $this->logActivity($request, 'gdpr.data_exported', $customer);

        $filename = Str::slug($customer->name).'-data-export-'.now()->format('Y-m-d').'.json';

        return response()->json($export)->withHeaders([
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function logActivity(Request $request, string $action, Company $customer, ?array $before = null, ?array $after = null): void
    {
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
}
