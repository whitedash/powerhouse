<?php

namespace Tests\Feature;

use App\Mail\WorkflowEmail;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkflowSendEmailTest extends TestCase
{
    use RefreshDatabase;

    private function seedSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * @param  array<int, array{action_type: string, config: array<string, mixed>}>  $actions
     */
    private function makeWorkflow(array $actions): Workflow
    {
        $workflow = Workflow::create([
            'name' => 'Email on submit',
            'is_active' => true,
            'trigger_type' => 'form_submitted',
            'created_by' => $this->seedSuperAdmin()->id,
        ]);

        foreach ($actions as $i => $a) {
            WorkflowAction::create([
                'workflow_id' => $workflow->id,
                'action_type' => $a['action_type'],
                'config' => $a['config'],
                'sort_order' => $i,
            ]);
        }

        return $workflow;
    }

    public function test_send_email_fixed_recipient_renders_subject_and_body(): void
    {
        Mail::fake();

        $this->makeWorkflow([[
            'action_type' => 'send_email',
            'config' => [
                'to_mode' => 'fixed',
                'to_address' => 'ops@example.com',
                'subject_template' => 'Thanks {{first_name}}',
                'body_template' => "Hi {{first_name}},\nWe got your message about {{topic}}.",
            ],
        ]]);

        app(WorkflowEngine::class)->trigger('form_submitted', [
            'first_name' => 'Pat',
            'topic' => 'billing',
        ]);

        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $m): bool => $m->hasTo('ops@example.com')
            && $m->subjectLine === 'Thanks Pat'
            && $m->body === "Hi Pat,\nWe got your message about billing.");
    }

    public function test_send_email_context_field_recipient(): void
    {
        Mail::fake();

        $this->makeWorkflow([[
            'action_type' => 'send_email',
            'config' => [
                'to_mode' => 'context_field',
                'to_field' => 'email',
                'subject_template' => 'Hello',
                'body_template' => 'Body',
            ],
        ]]);

        app(WorkflowEngine::class)->trigger('form_submitted', [
            'email' => 'lead@example.com',
            'first_name' => 'Sam',
        ]);

        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $m): bool => $m->hasTo('lead@example.com'));
    }

    public function test_send_email_skips_silently_when_no_recipient(): void
    {
        Mail::fake();

        // context_field pointing at a key the context doesn't carry → no address.
        $this->makeWorkflow([[
            'action_type' => 'send_email',
            'config' => [
                'to_mode' => 'context_field',
                'to_field' => 'missing_key',
                'subject_template' => 'Hi',
                'body_template' => 'Body',
            ],
        ]]);

        app(WorkflowEngine::class)->trigger('form_submitted', ['first_name' => 'Pat']);

        Mail::assertNothingSent();
    }

    public function test_email_is_not_sent_when_a_later_action_throws(): void
    {
        Mail::fake();

        // send_email (queued in-transaction) THEN a create_task whose
        // assigned_to FK is invalid → the insert throws, the transaction rolls
        // back, and the deferred email must never flush.
        $workflow = $this->makeWorkflow([
            [
                'action_type' => 'send_email',
                'config' => [
                    'to_mode' => 'fixed',
                    'to_address' => 'ops@example.com',
                    'subject_template' => 'Should not send',
                    'body_template' => 'Body',
                ],
            ],
            [
                'action_type' => 'create_task',
                'config' => [
                    'title_template' => 'Boom',
                    'assigned_to' => 999999, // non-existent user → FK violation
                    'created_by' => 999999,
                ],
            ],
        ]);

        app(WorkflowEngine::class)->trigger('form_submitted', ['first_name' => 'Pat']);

        // After-commit guarantee: nothing sent, and the run rolled back.
        Mail::assertNothingSent();
        $this->assertSame(0, $workflow->fresh()->run_count);
        $this->assertDatabaseMissing('tasks', ['title' => 'Boom']);
        $this->assertDatabaseMissing('activity_log', ['action' => 'workflow.email_sent']);
    }
}
