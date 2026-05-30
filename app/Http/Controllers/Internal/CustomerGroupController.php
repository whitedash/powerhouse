<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AccountGroup;
use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer groups (segments) — the user-facing vocabulary for the
 * underlying account_groups table. Used to tag customers as "VIP",
 * "Beta testers", etc., for filtering on the customer list and for
 * future bulk-action targets.
 *
 * The route prefix is /customer-groups even though the model is
 * AccountGroup — the model name is legacy and the rename would
 * force a data migration we don't need.
 */
class CustomerGroupController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $groups = AccountGroup::withCount('customers')
            ->with('createdBy:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (AccountGroup $g): array => [
                'id' => $g->id,
                'name' => $g->name,
                'description' => $g->description,
                'colour' => $g->colour,
                'customer_count' => (int) $g->customers_count,
                'created_by' => $g->createdBy?->name,
            ])
            ->all();

        return Inertia::render('Internal/CustomerGroups/Index', [
            'groups' => $groups,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Customer::class);

        $data = $this->validateGroup($request);

        DB::transaction(function () use ($data, $request): void {
            $group = AccountGroup::create([
                ...$data,
                'created_by' => $request->user()->id,
            ]);

            $this->log($request, 'customer_group.created', $group->id, after: [
                'name' => $group->name,
            ]);
        });

        return back()->with('success', 'Group created.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('create', Customer::class);

        $group = AccountGroup::findOrFail($id);
        $data = $this->validateGroup($request);

        DB::transaction(function () use ($group, $data, $request): void {
            $before = [
                'name' => $group->name,
                'colour' => $group->colour,
            ];
            $group->update($data);

            $this->log($request, 'customer_group.updated', $group->id, $before, [
                'name' => $group->name,
                'colour' => $group->colour,
            ]);
        });

        return back()->with('success', 'Group updated.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('create', Customer::class);

        $group = AccountGroup::findOrFail($id);

        DB::transaction(function () use ($group, $request): void {
            $snapshot = ['name' => $group->name];
            // Detach memberships explicitly so the pivot rows go even
            // though FK already cascades — keeps the audit log clean.
            $group->customers()->detach();
            $group->delete();

            $this->log($request, 'customer_group.deleted', 0, before: $snapshot);
        });

        return back()->with('success', 'Group removed.');
    }

    public function addMember(int $groupId, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
        ]);

        $group = AccountGroup::findOrFail($groupId);
        $customer = Customer::findOrFail($data['customer_id']);
        Gate::authorize('update', $customer);

        // syncWithoutDetaching is idempotent — re-adding an existing
        // membership doesn't fire an integrity error or fork the row.
        $group->customers()->syncWithoutDetaching([
            $data['customer_id'] => ['created_at' => now()],
        ]);

        $this->log($request, 'customer_group.member_added', $group->id, after: [
            'customer_id' => $data['customer_id'],
            'group_name' => $group->name,
        ]);

        return back()->with('success', sprintf('%s added to %s.', $customer->name, $group->name));
    }

    public function removeMember(int $groupId, int $customerId, Request $request): RedirectResponse
    {
        $group = AccountGroup::findOrFail($groupId);
        $customer = Customer::findOrFail($customerId);
        Gate::authorize('update', $customer);

        $group->customers()->detach($customerId);

        $this->log($request, 'customer_group.member_removed', $group->id, after: [
            'customer_id' => $customerId,
            'group_name' => $group->name,
        ]);

        return back()->with('success', sprintf('%s removed from %s.', $customer->name, $group->name));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGroup(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'colour' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
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
            'entity_type' => 'customer_group',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
