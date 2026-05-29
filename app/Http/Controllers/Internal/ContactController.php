<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Per-customer contacts CRUD. Lives outside CustomerController so
 * the customer detail page can target tight, focused endpoints
 * (store/update/destroy/setPrimary) rather than wrestling with the
 * sprawling customer-wide controller.
 *
 * Authorisation is delegated to the existing 'update' policy on
 * Customer — anyone who can edit the customer can edit its contacts.
 */
class ContactController extends Controller
{
    private const ROLES = ['owner', 'manager', 'accounts', 'other'];

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:contacts,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(self::ROLES)],
            'is_primary' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $customer = Customer::findOrFail($data['customer_id']);
        Gate::authorize('update', $customer);

        $contact = DB::transaction(function () use ($data, $request): Contact {
            $wantsPrimary = $request->boolean('is_primary');
            $isFirst = Contact::where('customer_id', $data['customer_id'])->doesntExist();

            // First contact on a customer is always primary, even if the
            // form left the toggle off — somebody has to receive invoices.
            $isPrimary = $wantsPrimary || $isFirst;

            // If we're flipping the primary, clear it on every other row
            // BEFORE inserting so the new row can land as primary cleanly.
            if ($isPrimary) {
                Contact::where('customer_id', $data['customer_id'])
                    ->update(['is_primary' => false]);
            }

            return Contact::create(array_merge($data, [
                'role' => $data['role'] ?? 'other',
                'is_primary' => $isPrimary,
            ]));
        });

        $this->log($request, 'contact.created', $contact, after: [
            'name' => $contact->name,
            'email' => $contact->email,
            'is_primary' => $contact->is_primary,
        ]);

        return back()->with('success', "Contact '{$contact->display_name}' added.");
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $contact = Contact::findOrFail($id);
        Gate::authorize('update', $contact->customer);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('contacts', 'email')->ignore($contact->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(self::ROLES)],
            'is_primary' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $contact->only(['name', 'email', 'phone', 'job_title', 'role', 'is_primary']);

        DB::transaction(function () use ($contact, $data, $request) {
            if ($request->boolean('is_primary') && ! $contact->is_primary) {
                Contact::where('customer_id', $contact->customer_id)
                    ->where('id', '!=', $contact->id)
                    ->update(['is_primary' => false]);
            }

            $contact->fill(array_merge($data, [
                'role' => $data['role'] ?? $contact->role,
                'is_primary' => $request->boolean('is_primary', $contact->is_primary),
            ]))->save();
        });

        $this->log($request, 'contact.updated', $contact, before: $before, after: $contact->only(['name', 'email', 'phone', 'job_title', 'role', 'is_primary']));

        return back()->with('success', "Contact '{$contact->display_name}' updated.");
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $contact = Contact::findOrFail($id);
        Gate::authorize('update', $contact->customer);

        // A contact with a live portal account is a relationship, not
        // just a record — revoke first so portal history isn't orphaned
        // mid-conversation.
        if ($contact->portalUser()->exists()) {
            return back()->withErrors([
                'contact' => "{$contact->display_name} has portal access. Revoke it first before deleting this contact.",
            ]);
        }

        $remaining = Contact::where('customer_id', $contact->customer_id)->count();
        if ($remaining === 1) {
            return back()->withErrors([
                'contact' => 'Cannot delete the only contact. Add another contact first.',
            ]);
        }

        DB::transaction(function () use ($contact) {
            // If we're about to delete the primary, promote the oldest
            // sibling so the customer never sits without a primary —
            // invoice / portal-invite flows assume one exists.
            if ($contact->is_primary) {
                $next = Contact::where('customer_id', $contact->customer_id)
                    ->where('id', '!=', $contact->id)
                    ->oldest()
                    ->first();

                if ($next) {
                    $next->is_primary = true;
                    $next->save();
                }
            }

            $contact->delete();
        });

        $this->log($request, 'contact.deleted', $contact, before: [
            'name' => $contact->name,
            'email' => $contact->email,
        ]);

        return back()->with('success', "Contact '{$contact->display_name}' deleted.");
    }

    public function setPrimary(int $id, Request $request): RedirectResponse
    {
        $contact = Contact::findOrFail($id);
        Gate::authorize('update', $contact->customer);

        if ($contact->is_primary) {
            return back()->with('success', "{$contact->display_name} is already the primary contact.");
        }

        DB::transaction(function () use ($contact) {
            Contact::where('customer_id', $contact->customer_id)
                ->update(['is_primary' => false]);

            $contact->is_primary = true;
            $contact->save();
        });

        $this->log($request, 'contact.set_primary', $contact, after: ['name' => $contact->name]);

        return back()->with('success', "{$contact->display_name} is now the primary contact.");
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function log(Request $request, string $action, Contact $contact, array $before = [], array $after = []): void
    {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => Contact::class,
            'entity_id' => $contact->id,
            'before' => $before ?: null,
            'after' => $after ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
