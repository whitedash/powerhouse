<?php

namespace Tests\Feature;

use App\Mail\WorkflowEmail;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase A — field-bound action parameters (value source). A create_task due date
 * and a send_email recipient can come from a static value or a form field, with
 * full backward compatibility for the legacy send_email to_mode keys.
 */
class WorkflowActionBindingsTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $config */
    private function fireCreateTask(array $config, array $payload): Task
    {
        $u = User::factory()->create(['role' => 'super_admin']);
        $wf = Workflow::create([
            'name' => 'Task WF', 'is_active' => true, 'trigger_type' => 'form_submitted',
            'trigger_config' => null, 'run_count' => 0, 'created_by' => $u->id,
        ]);
        $wf->actions()->create([
            'action_type' => 'create_task',
            // assigned_to/created_by set so the (NOT NULL) Task insert succeeds —
            // this test exercises the due_at value source, not create_task's
            // pre-existing assigned_to handling.
            'config' => array_merge(['created_by' => $u->id, 'assigned_to' => $u->id], $config),
            'sort_order' => 0,
        ]);

        app(WorkflowEngine::class)->trigger('form_submitted', array_merge($payload, ['form_id' => 1]));

        return Task::latest('id')->firstOrFail();
    }

    /** @param array<string, mixed> $config */
    private function fireSendEmail(array $config, array $payload): void
    {
        $u = User::factory()->create(['role' => 'super_admin']);
        $wf = Workflow::create([
            'name' => 'Email WF', 'is_active' => true, 'trigger_type' => 'form_submitted',
            'trigger_config' => null, 'run_count' => 0, 'created_by' => $u->id,
        ]);
        $wf->actions()->create([
            'action_type' => 'send_email',
            'config' => array_merge(['subject_template' => 'Hi', 'body_template' => 'Body'], $config),
            'sort_order' => 0,
        ]);

        app(WorkflowEngine::class)->trigger('form_submitted', array_merge($payload, ['form_id' => 1]));
    }

    // ── create_task due date ──────────────────────────────────────────────

    public function test_due_at_binds_to_a_datetime_field(): void
    {
        $task = $this->fireCreateTask(
            ['due_at_source' => ['source' => 'field', 'field_key' => 'appt']],
            ['appt' => '2026-07-15T14:30'],   // what <input type=datetime-local> posts
        );

        $this->assertSame('2026-07-15 14:30', $task->due_at->format('Y-m-d H:i'));
    }

    public function test_empty_absent_or_unparseable_field_falls_back_to_offset_and_never_nulls(): void
    {
        $cases = [
            ['appt' => ''],            // empty (single-step keeps empty strings)
            [],                        // absent key
            ['appt' => 'not-a-date'],  // unparseable
            ['appt' => '2026-07-15T14:30:00'], // has seconds → strict Y-m-d\TH:i mismatch
        ];
        foreach ($cases as $payload) {
            $task = $this->fireCreateTask(
                ['due_at_source' => ['source' => 'field', 'field_key' => 'appt'], 'due_in_days' => 5],
                $payload,
            );
            // Falls back to the +5-day @ 09:00 offset — never null, never throws.
            $this->assertNotNull($task->due_at);
            $this->assertSame(now()->addDays(5)->toDateString(), $task->due_at->toDateString());
            $this->assertSame('09:00', $task->due_at->format('H:i'));
        }
    }

    public function test_static_source_and_no_descriptor_use_the_due_in_days_offset(): void
    {
        // Explicit static source.
        $a = $this->fireCreateTask(['due_at_source' => ['source' => 'static'], 'due_in_days' => 7], []);
        $this->assertSame(now()->addDays(7)->toDateString(), $a->due_at->toDateString());
        $this->assertSame('09:00', $a->due_at->format('H:i'));

        // Backward compatible: a create_task with NO descriptor behaves as before.
        $b = $this->fireCreateTask(['due_in_days' => 2], []);
        $this->assertSame(now()->addDays(2)->toDateString(), $b->due_at->toDateString());
    }

    // ── send_email recipient ──────────────────────────────────────────────

    public function test_field_source_recipient_resolves_to_the_bound_email_field(): void
    {
        Mail::fake();
        $this->fireSendEmail(
            ['recipient_source' => ['source' => 'field', 'field_key' => 'email']],
            ['email' => 'lead@example.com'],
        );
        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $m): bool => $m->hasTo('lead@example.com'));
    }

    public function test_static_source_recipient_resolves_to_the_fixed_address(): void
    {
        Mail::fake();
        $this->fireSendEmail(
            ['recipient_source' => ['source' => 'static', 'static' => 'ops@example.com']],
            [],
        );
        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $m): bool => $m->hasTo('ops@example.com'));
    }

    public function test_legacy_context_field_config_still_resolves(): void
    {
        Mail::fake();
        $this->fireSendEmail(
            ['to_mode' => 'context_field', 'to_field' => 'email'],
            ['email' => 'legacy-field@example.com'],
        );
        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $m): bool => $m->hasTo('legacy-field@example.com'));
    }

    public function test_legacy_fixed_config_still_resolves(): void
    {
        Mail::fake();
        $this->fireSendEmail(
            ['to_mode' => 'fixed', 'to_address' => 'legacy-fixed@example.com'],
            [],
        );
        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $m): bool => $m->hasTo('legacy-fixed@example.com'));
    }
}
