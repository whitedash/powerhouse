<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 13 — form builder CRUD gating.
 *
 * The builder's index/store/update/destroy were ungated (auth+role only); this
 * wires each to its permission server-side via route middleware (the proposals
 * pattern, since the forms group mixes reads and mutations):
 *   - index            → forms.access   (config + webhook_secret read)
 *   - store/update/destroy → forms.manage (integrity)
 *
 * The submissions PII read keeps its Issue-A forms.view_submissions gate
 * (UNCHANGED — guarded here as a regression so a later edit can't drop it), and
 * the theme sub-area keeps its FormThemePolicy gating (forms.access/manage).
 *
 * Each guard proves the gate BITES: a staff-enum user WITHOUT the permission is
 * 403'd before the controller runs; a holder gets past authorization; and a
 * super_admin bypasses via Gate::before. A default factory staff user is
 * access-identical to before (its day-1 grant already holds all three forms
 * permissions) — these gates only restrict custom roles that lack them.
 */
class FormBuilderEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Staff-enum user (passes role:super_admin,staff) holding ONLY $permissions. */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'custom_'.uniqid(), 'guard_name' => 'web']);
        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }
        $user = User::factory()->create();   // enum staff
        $user->syncRoles([$role->name]);     // replace the auto-assigned staff role

        return $user->fresh();
    }

    /** super_admin stripped of every role/permission — proves the bypass is enum-driven. */
    private function bareSuperAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create();
        $admin->syncRoles([]);
        $admin->syncPermissions([]);

        return $admin;
    }

    private function makeForm(): Form
    {
        return Form::create([
            'name' => 'Contact form',
            'slug' => 'contact-'.uniqid(),
            'status' => 'active',
            'webhook_secret' => str_repeat('a', 64),
            'created_by' => User::factory()->create()->id,
        ]);
    }

    /** Minimal valid builder payload (one text field on one step). */
    private function formPayload(): array
    {
        return [
            'name' => 'Form',
            'slug' => 'ok-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'steps' => [[
                'label' => 'Step 1',
                'sort_order' => 0,
                'fields' => [
                    ['label' => 'Name', 'field_key' => 'name', 'type' => 'text', 'is_required' => true, 'width' => 'full', 'sort_order' => 0],
                ],
            ]],
        ];
    }

    // ─────────── index → forms.access ───────────

    public function test_index_403_without_forms_access(): void
    {
        // Holds an unrelated permission, NOT forms.access.
        $this->actingAs($this->userWith(['companies.access']))
            ->get('/forms')
            ->assertForbidden();
    }

    public function test_index_ok_with_forms_access(): void
    {
        $this->actingAs($this->userWith(['forms.access']))
            ->get('/forms')
            ->assertOk();
    }

    public function test_index_superadmin_bypass(): void
    {
        $this->actingAs($this->bareSuperAdmin())
            ->get('/forms')
            ->assertOk();
    }

    // ─────────── store → forms.manage ───────────

    public function test_store_403_without_forms_manage(): void
    {
        // forms.access lets you VIEW the builder but NOT create.
        $this->actingAs($this->userWith(['forms.access']))
            ->post('/forms', $this->formPayload())
            ->assertForbidden();

        $this->assertSame(0, Form::count());
    }

    public function test_store_ok_with_forms_manage(): void
    {
        $this->actingAs($this->userWith(['forms.manage']))
            ->post('/forms', $this->formPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Form::count());
    }

    public function test_store_superadmin_bypass(): void
    {
        $this->actingAs($this->bareSuperAdmin())
            ->post('/forms', $this->formPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Form::count());
    }

    // ─────────── update → forms.manage ───────────

    public function test_update_403_without_forms_manage(): void
    {
        $form = $this->makeForm();

        $this->actingAs($this->userWith(['forms.access']))
            ->put("/forms/{$form->id}", $this->formPayload())
            ->assertForbidden();

        // Name unchanged — the gate fired before the controller mutated anything.
        $this->assertSame('Contact form', $form->fresh()->name);
    }

    public function test_update_ok_with_forms_manage(): void
    {
        $form = $this->makeForm();

        $this->actingAs($this->userWith(['forms.manage']))
            ->put("/forms/{$form->id}", array_merge($this->formPayload(), ['name' => 'Renamed']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $form->fresh()->name);
    }

    // ─────────── destroy → forms.manage ───────────

    public function test_destroy_403_without_forms_manage(): void
    {
        $form = $this->makeForm();

        $this->actingAs($this->userWith(['forms.access']))
            ->delete("/forms/{$form->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('forms', ['id' => $form->id]);
    }

    public function test_destroy_ok_with_forms_manage(): void
    {
        $form = $this->makeForm();

        $this->actingAs($this->userWith(['forms.manage']))
            ->delete("/forms/{$form->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('forms', ['id' => $form->id]);
    }

    // ─────────── submissions → forms.view_submissions (Issue-A, UNCHANGED) ───────────

    /** Regression guard: the Issue-A PII gate must stay on submissions. */
    public function test_submissions_still_403_without_view_submissions(): void
    {
        $form = $this->makeForm();

        // Has builder access (and even manage) but NOT the PII permission.
        $this->actingAs($this->userWith(['forms.access', 'forms.manage']))
            ->get("/forms/{$form->id}/submissions")
            ->assertForbidden();
    }

    public function test_submissions_ok_with_view_submissions(): void
    {
        $form = $this->makeForm();

        $this->actingAs($this->userWith(['forms.view_submissions']))
            ->get("/forms/{$form->id}/submissions")
            ->assertOk();
    }

    // ─────────── themes → FormThemePolicy (forms.access, UNCHANGED) ───────────

    public function test_theme_index_ok_with_forms_access(): void
    {
        $this->actingAs($this->userWith(['forms.access']))
            ->get('/forms/themes')
            ->assertOk();
    }

    public function test_theme_index_403_without_forms_access(): void
    {
        $this->actingAs($this->userWith(['companies.access']))
            ->get('/forms/themes')
            ->assertForbidden();
    }
}
