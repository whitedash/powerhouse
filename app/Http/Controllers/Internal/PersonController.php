<?php

namespace App\Http\Controllers\Internal;

use App\Enums\PersonRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttachPersonRequest;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Models\Company;
use App\Models\CustomerPerson;
use App\Models\Person;
use App\Services\PersonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * People — the cross-company human-identity layer. Distinct from the
 * per-customer ContactController: a Person can be linked to many
 * companies via the customer_person pivot, each with a role.
 *
 * All writes delegate to PersonService (transactions + activity_log).
 * Every id-accepting method uses findOrFail + a policy authorize, per
 * the IDOR rule.
 */
class PersonController extends Controller
{
    public function __construct(private readonly PersonService $people) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Person::class);

        $search = trim((string) $request->query('search', ''));

        $people = Person::query()
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search): void {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->withCount('companies')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Person $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'email' => $p->email,
                'phone' => $p->phone,
                'company_count' => (int) $p->companies_count,
            ]);

        return Inertia::render('Internal/People/Index', [
            'people' => $people,
            'filters' => ['search' => $search],
        ]);
    }

    public function show(int $id): Response
    {
        $person = Person::with([
            'companies:id,name,type,pipeline_stage',
            'createdBy:id,name',
        ])->findOrFail($id);

        Gate::authorize('view', $person);

        return Inertia::render('Internal/People/Show', [
            'person' => [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'phone' => $person->phone,
                'notes' => $person->notes,
                'created_by' => $person->createdBy?->name,
                'created_at' => $person->created_at?->toIso8601String(),
                'companies' => $person->companies->map(function (Company $c): array {
                    // Larastan can't type the belongsToMany ->using() pivot, so
                    // narrow it once here; the runtime access is correct.
                    /** @var CustomerPerson $pivot */
                    $pivot = $c->pivot; // @phpstan-ignore property.notFound

                    return [
                        'id' => $c->id,
                        'name' => $c->name,
                        'type' => $c->type,
                        'pipeline_stage' => $c->pipeline_stage,
                        'role' => $pivot->role->value,
                        'role_label' => $pivot->role->label(),
                        'job_title' => $pivot->job_title,
                    ];
                })->all(),
            ],
            'roleOptions' => PersonRole::options(),
        ]);
    }

    public function store(StorePersonRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $person = $this->people->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], $request->user());

        // Attach-on-create: when the customer page sends a company to link
        // to, do it in the same request and return to the referring page
        // instead of the (otherwise) person detail page.
        if (! empty($data['attach_customer_id'])) {
            $customer = Company::findOrFail($data['attach_customer_id']);
            Gate::authorize('update', $customer);

            $this->people->attachCompany(
                $person,
                $customer,
                PersonRole::from($data['attach_role']),
                $data['attach_job_title'] ?? null,
                $request->user(),
            );

            return back()->with('success', "{$person->display_name} created and linked to {$customer->name}.");
        }

        return redirect()
            ->route('internal.people.show', $person->id)
            ->with('success', "Person '{$person->display_name}' created.");
    }

    public function update(int $id, UpdatePersonRequest $request): RedirectResponse
    {
        $person = Person::findOrFail($id);
        Gate::authorize('update', $person);

        $this->people->update($person, $request->validated(), $request->user());

        return back()->with('success', "Person '{$person->display_name}' updated.");
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $person = Person::findOrFail($id);
        Gate::authorize('delete', $person);

        $this->people->delete($person, $request->user());

        return redirect()
            ->route('internal.people.index')
            ->with('success', "Person '{$person->display_name}' deleted.");
    }

    public function attachCompany(int $id, AttachPersonRequest $request): RedirectResponse
    {
        $person = Person::findOrFail($id);
        Gate::authorize('update', $person);

        $customer = Company::findOrFail($request->validated()['customer_id']);
        // Confirm the operator may touch this company too, not just the person.
        Gate::authorize('update', $customer);

        $this->people->attachCompany(
            $person,
            $customer,
            PersonRole::from($request->validated()['role']),
            $request->validated()['job_title'] ?? null,
            $request->user(),
        );

        return back()->with('success', "{$person->display_name} linked to {$customer->name}.");
    }

    public function setRole(int $id, int $customerId, AttachPersonRequest $request): RedirectResponse
    {
        $person = Person::findOrFail($id);
        Gate::authorize('update', $person);

        $customer = Company::findOrFail($customerId);
        Gate::authorize('update', $customer);

        $this->people->setRole(
            $person,
            $customer,
            PersonRole::from($request->validated()['role']),
            $request->validated()['job_title'] ?? null,
            $request->user(),
        );

        return back()->with('success', 'Role updated.');
    }

    public function detachCompany(int $id, int $customerId, Request $request): RedirectResponse
    {
        $person = Person::findOrFail($id);
        Gate::authorize('update', $person);

        $customer = Company::findOrFail($customerId);
        Gate::authorize('update', $customer);

        $this->people->detachCompany($person, $customer, $request->user());

        return back()->with('success', "{$person->display_name} unlinked from {$customer->name}.");
    }
}
