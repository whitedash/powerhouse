<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Sibling silent-swallow fixes. actionCreateLead and actionAddNote both write a
 * NOT NULL created_by FK→users and previously fell back to a magic user id 1
 * (which can FK-fail and be swallowed by trigger() — a silent no-op). Both now
 * resolve created_by through resolveWorkflowActorId (configured-if-it-exists →
 * first super_admin), with a Log::warning + graceful skip when no super_admin
 * exists, mirroring the create_task fix.
 */
class WorkflowActorFallbackTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $config */
    private function makeWorkflow(int $creatorId, string $actionType, array $config): Workflow
    {
        $workflow = Workflow::create([
            'name' => 'Actor fallback WF',
            'is_active' => true,
            'trigger_type' => 'form_submitted',
            'trigger_config' => null,
            'run_count' => 0,
            'created_by' => $creatorId,
        ]);
        $workflow->actions()->create([
            'action_type' => $actionType,
            'config' => $config,
            'sort_order' => 0,
        ]);

        return $workflow;
    }

    // ── create_lead ───────────────────────────────────────────────────────

    public function test_create_lead_with_no_creator_falls_back_to_the_super_admin(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->makeWorkflow($admin->id, 'create_lead', [
            'first_name_field' => 'first_name',
            'email_field' => 'email',
            // No created_by — previously fell back to the magic id 1.
        ]);

        app(WorkflowEngine::class)->trigger('form_submitted', [
            'first_name' => 'Dana',
            'email' => 'dana@example.com',
        ]);

        $lead = Lead::latest('id')->firstOrFail();
        $this->assertSame($admin->id, $lead->created_by, 'created_by resolves to the super_admin, not magic 1');
        $this->assertSame('Dana', $lead->first_name);
        $this->assertSame('dana@example.com', $lead->email);
    }

    public function test_create_lead_skips_and_warns_when_no_super_admin_exists(): void
    {
        Log::spy();
        // The workflow's own created_by needs a real user — but NOT a super_admin,
        // so the action's actor resolution has nothing to fall back to.
        $staff = User::factory()->create(['role' => 'staff']);
        $this->makeWorkflow($staff->id, 'create_lead', ['first_name_field' => 'first_name']);

        app(WorkflowEngine::class)->trigger('form_submitted', ['first_name' => 'Dana']);

        $this->assertDatabaseCount('leads', 0);
        Log::shouldHaveReceived('warning')
            ->with('Workflow create_lead skipped: no default creator could be resolved', \Mockery::on(
                fn (array $ctx): bool => ($ctx['action_type'] ?? null) === 'create_lead'
            ))
            ->once();
    }

    // ── add_note ──────────────────────────────────────────────────────────

    public function test_add_note_with_no_creator_falls_back_to_the_super_admin(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $customer = Company::create(['name' => 'Acme']);
        $this->makeWorkflow($admin->id, 'add_note', [
            'content_template' => 'Note about {{first_name}}',
            // No created_by.
        ]);

        app(WorkflowEngine::class)->trigger('form_submitted', [
            'first_name' => 'Dana',
            'customer_id' => $customer->id,
        ]);

        $note = Note::latest('id')->firstOrFail();
        $this->assertSame($admin->id, $note->created_by, 'created_by resolves to the super_admin, not magic 1');
        $this->assertSame($customer->id, $note->customer_id);
        $this->assertStringContainsString('Dana', (string) $note->body);
    }

    public function test_add_note_skips_and_warns_when_no_super_admin_exists(): void
    {
        Log::spy();
        $staff = User::factory()->create(['role' => 'staff']);
        $customer = Company::create(['name' => 'Acme']);
        $this->makeWorkflow($staff->id, 'add_note', ['content_template' => 'Note about {{first_name}}']);

        app(WorkflowEngine::class)->trigger('form_submitted', [
            'first_name' => 'Dana',
            'customer_id' => $customer->id,
        ]);

        $this->assertDatabaseCount('notes', 0);
        Log::shouldHaveReceived('warning')
            ->with('Workflow add_note skipped: no default creator could be resolved', \Mockery::on(
                fn (array $ctx): bool => ($ctx['action_type'] ?? null) === 'add_note'
            ))
            ->once();
    }
}
