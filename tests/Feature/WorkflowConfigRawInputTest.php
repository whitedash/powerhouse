<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hardening for the excludeUnvalidatedArrayKeys footgun on workflow actions.
 *
 * A workflow action's polymorphic config is persisted from RAW input, not the
 * pruned validated() copy. The per-index rules type-check the known subkeys but,
 * as child rules under actions.*.config, would strip any config key without an
 * explicit rule out of validated(). Reading from raw input keeps the blob intact
 * and decoupled from WorkflowEngine's reads.
 */
class WorkflowConfigRawInputTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_config_key_without_an_explicit_rule_persists_intact(): void
    {
        $this->actingAs($this->superAdmin())->post('/workflows', [
            'name' => 'Notify ops',
            'trigger_type' => 'form_submitted',
            'is_active' => true,
            'actions' => [[
                'action_type' => 'send_email',
                'config' => [
                    'to_mode' => 'fixed',
                    'to_address' => 'ops@example.com',
                    'subject_template' => 'Hi',
                    'body_template' => 'Body',
                    // No explicit per-index rule covers this key. Read from validated()
                    // it would be pruned; read from raw input it must survive.
                    'reply_to_field' => 'email',
                ],
            ]],
        ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $config = WorkflowAction::sole()->config;
        $this->assertSame('email', $config['reply_to_field'] ?? null, 'unruled config key was pruned away');
        // Ruled keys are intact too.
        $this->assertSame('fixed', $config['to_mode']);
        $this->assertSame('ops@example.com', $config['to_address']);
    }
}
