<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * update_lead_status hardening. Before this fix the action did a bare
 * Lead::where('id', $context['lead_id'])->update(...) with no scoping, no
 * enum validation, and no audit row — and trigger() seeded $context from the
 * raw public request payload, so an unauthenticated form/webhook POST carrying
 * a lead_id could flip ANY lead's status via any active workflow holding this
 * action. Now: lead_id is stripped from the inbound payload (only create_lead
 * may set it), the target status is enum-validated, and the write is audited.
 */
class WorkflowUpdateLeadStatusScopingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * @param  list<array{action_type: string, config: array<string, mixed>}>  $actions
     */
    private function makeWorkflow(int $creatorId, array $actions): Workflow
    {
        $workflow = Workflow::create([
            'name' => 'Status WF',
            'is_active' => true,
            'trigger_type' => 'form_submitted',
            'trigger_config' => null,
            'run_count' => 0,
            'created_by' => $creatorId,
        ]);
        foreach ($actions as $i => $a) {
            $workflow->actions()->create([
                'action_type' => $a['action_type'],
                'config' => $a['config'],
                'sort_order' => $i,
            ]);
        }

        return $workflow;
    }

    public function test_injected_lead_id_from_payload_cannot_mutate_an_unrelated_lead(): void
    {
        $admin = $this->admin();
        $victim = Lead::create(['first_name' => 'Victim', 'status' => 'new', 'created_by' => $admin->id]);

        // A workflow with update_lead_status but NO create_lead — so the only
        // way lead_id could reach the action is the (now-stripped) payload.
        $this->makeWorkflow($admin->id, [
            ['action_type' => 'update_lead_status', 'config' => ['status' => 'won']],
        ]);

        // Attacker-controlled payload naming the victim's id, exactly as a
        // public form POST would arrive.
        app(WorkflowEngine::class)->trigger('form_submitted', [
            'lead_id' => $victim->id,
            'form_id' => 1,
        ]);

        $this->assertSame('new', $victim->fresh()->status, 'unrelated lead must be untouched');
        $this->assertDatabaseMissing('activity_log', [
            'action' => 'lead.status_changed',
            'entity_id' => $victim->id,
        ]);
    }

    public function test_legitimate_same_context_update_still_works_and_is_audited(): void
    {
        $admin = $this->admin();

        // The legitimate shape: create_lead seeds a trusted lead_id, then
        // update_lead_status acts on that same lead.
        $this->makeWorkflow($admin->id, [
            ['action_type' => 'create_lead', 'config' => ['first_name_field' => 'first_name', 'email_field' => 'email']],
            ['action_type' => 'update_lead_status', 'config' => ['status' => 'qualified']],
        ]);

        app(WorkflowEngine::class)->trigger('form_submitted', [
            'first_name' => 'Dana',
            'email' => 'dana@example.com',
            'form_id' => 1,
        ]);

        $lead = Lead::where('email', 'dana@example.com')->firstOrFail();
        $this->assertSame('qualified', $lead->status, 'the workflow-created lead is transitioned');

        // Per-lead audit row in the lead.status_changed convention.
        $log = ActivityLog::where('action', 'lead.status_changed')
            ->where('entity_type', 'lead')
            ->where('entity_id', $lead->id)
            ->firstOrFail();
        $this->assertSame('new', $log->after['from']);
        $this->assertSame('qualified', $log->after['to']);
        $this->assertSame('workflow', $log->after['via']);
        $this->assertSame('system', $log->user_role);
        $this->assertNull($log->user_id);
    }

    public function test_injected_lead_id_is_ignored_even_with_a_create_lead_present(): void
    {
        $admin = $this->admin();
        $victim = Lead::create(['first_name' => 'Victim', 'status' => 'new', 'created_by' => $admin->id]);

        $this->makeWorkflow($admin->id, [
            ['action_type' => 'create_lead', 'config' => ['first_name_field' => 'first_name']],
            ['action_type' => 'update_lead_status', 'config' => ['status' => 'won']],
        ]);

        // Payload carries the victim id, but create_lead's own lead_id must win.
        app(WorkflowEngine::class)->trigger('form_submitted', [
            'lead_id' => $victim->id,
            'first_name' => 'Dana',
            'form_id' => 1,
        ]);

        $this->assertSame('new', $victim->fresh()->status, 'the injected victim id is ignored');
        $created = Lead::where('first_name', 'Dana')->firstOrFail();
        $this->assertSame('won', $created->status, 'the workflow-created lead is the one transitioned');
    }

    public function test_invalid_status_value_is_rejected_without_writing_or_voiding_the_run(): void
    {
        Log::spy();
        $admin = $this->admin();

        $this->makeWorkflow($admin->id, [
            ['action_type' => 'create_lead', 'config' => ['first_name_field' => 'first_name']],
            ['action_type' => 'update_lead_status', 'config' => ['status' => 'archived']], // not a real status
        ]);

        app(WorkflowEngine::class)->trigger('form_submitted', [
            'first_name' => 'Dana',
            'form_id' => 1,
        ]);

        // create_lead still committed (the invalid status skips, not voids).
        $lead = Lead::where('first_name', 'Dana')->firstOrFail();
        $this->assertSame('new', $lead->status, 'the bogus value never reached the column');
        $this->assertDatabaseMissing('activity_log', [
            'action' => 'lead.status_changed',
            'entity_id' => $lead->id,
        ]);
        Log::shouldHaveReceived('warning')
            ->with('Workflow update_lead_status skipped: invalid target status', \Mockery::on(
                fn (array $ctx): bool => ($ctx['status'] ?? null) === 'archived'
            ))
            ->once();
    }
}
