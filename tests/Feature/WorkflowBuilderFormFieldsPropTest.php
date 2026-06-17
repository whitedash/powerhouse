<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkflowBuilderFormFieldsPropTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_workflows_index_forms_prop_includes_field_keys_and_labels(): void
    {
        $form = Form::create([
            'name' => 'Contact',
            'slug' => 'contact-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'submit_button_text' => 'Submit',
            'webhook_secret' => Str::random(32),
            'created_by' => $this->superAdmin()->id,
        ]);
        $form->fields()->create([
            'label' => 'Your email', 'field_key' => 'email', 'type' => 'email',
            'is_required' => true, 'sort_order' => 0,
        ]);
        $form->fields()->create([
            'label' => 'Message', 'field_key' => 'message', 'type' => 'textarea',
            'is_required' => true, 'sort_order' => 1,
        ]);

        $this->actingAs($this->superAdmin())
            ->get('/workflows')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Internal/Workflows/Index')
                ->has('forms.0.fields', 2)
                ->where('forms.0.fields.0.key', 'email')
                ->where('forms.0.fields.0.label', 'Your email')
                ->where('forms.0.fields.1.key', 'message')
                ->where('forms.0.fields.1.label', 'Message')
            );
    }
}
