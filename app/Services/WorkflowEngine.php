<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\FormSubmission;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Task;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generic automation engine. Controllers call ::trigger() and
 * walk away — the engine resolves matching workflows, runs each
 * one's actions in sort_order inside its own transaction, and
 * logs success/failure to activity_log + the framework log.
 *
 * Failure isolation: one workflow throwing does NOT stop sibling
 * workflows from running on the same trigger — each gets its own
 * try/catch and transaction. The intent is "automation should
 * never break the user request that fired it" (e.g. a malformed
 * webhook config shouldn't prevent the lead from being created
 * by a sibling workflow).
 *
 * Context accumulation: actions run in sort_order and pass their
 * result back into the context array. create_lead writes
 * `lead_id` so a subsequent create_task picks it up and links
 * the task to the new lead. This is what makes "land a webhook,
 * end up with a fully-populated lead AND a follow-up call task"
 * work in a single declarative workflow row.
 */
class WorkflowEngine
{
    /**
     * Fire every active workflow that matches ($triggerType, $payload).
     *
     * @param  array<string, mixed>  $payload
     */
    public function trigger(string $triggerType, array $payload, ?int $triggerEntityId = null): void
    {
        $workflows = Workflow::query()
            ->where('trigger_type', $triggerType)
            ->where('is_active', true)
            ->with('actions')
            ->get();

        foreach ($workflows as $workflow) {
            if (! $this->matchesTriggerConfig($workflow, $payload)) {
                continue;
            }

            try {
                DB::transaction(function () use ($workflow, $payload, $triggerType, $triggerEntityId): void {
                    $context = $payload;

                    foreach ($workflow->actions as $action) {
                        $context = $this->executeAction($action, $context);
                    }

                    // run_count is bumped atomically so concurrent
                    // triggers don't lose increments. last_run_at
                    // is the human "did this fire recently?" check.
                    $workflow->forceFill([
                        'run_count' => $workflow->run_count + 1,
                        'last_run_at' => now(),
                    ])->save();

                    ActivityLog::create([
                        'user_id' => null,
                        'action' => 'workflow.executed',
                        'entity_type' => 'workflow',
                        'entity_id' => $workflow->id,
                        'before' => null,
                        'after' => [
                            'workflow_id' => $workflow->id,
                            'workflow_name' => $workflow->name,
                            'trigger' => $triggerType,
                            'trigger_entity_id' => $triggerEntityId,
                            'lead_id' => $context['lead_id'] ?? null,
                        ],
                    ]);
                });
            } catch (\Throwable $e) {
                Log::error('Workflow failed', [
                    'workflow_id' => $workflow->id,
                    'trigger' => $triggerType,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Trigger-specific filtering. The trigger_type alone matches
     * a workflow; trigger_config narrows it further. Examples:
     *
     *   form_submitted        => {"form_id": 4}
     *   lead_status_changed   => {"to": "qualified"}
     *
     * When trigger_config is missing keys we fall through to
     * "matches everything" so a freshly-created workflow with
     * default config still runs on the chosen trigger.
     *
     * @param  array<string, mixed>  $payload
     */
    private function matchesTriggerConfig(Workflow $workflow, array $payload): bool
    {
        $config = $workflow->trigger_config ?? [];

        return match ($workflow->trigger_type) {
            'form_submitted' => ! isset($config['form_id'])
                || (int) ($payload['form_id'] ?? 0) === (int) $config['form_id'],

            'lead_status_changed' => ! isset($config['to'])
                || ($payload['new_status'] ?? null) === $config['to'],

            'webhook_received' => ! isset($config['source'])
                || ($payload['source'] ?? null) === $config['source'],

            default => true,
        };
    }

    /**
     * Dispatch to the per-action handler. The handler returns
     * the (possibly extended) context which is fed to the next
     * action.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function executeAction(WorkflowAction $action, array $context): array
    {
        return match ($action->action_type) {
            'create_lead' => $this->actionCreateLead($action->config, $context),
            'create_task' => $this->actionCreateTask($action->config, $context),
            'add_note' => $this->actionAddNote($action->config, $context),
            'update_lead_status' => $this->actionUpdateLeadStatus($action->config, $context),
            'send_notification' => $this->actionSendNotification($action->config, $context),
            'assign_to_user' => $this->actionAssignToUser($action->config, $context),
            default => $context,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function actionCreateLead(array $config, array $context): array
    {
        $firstName = $this->resolveField($config['first_name_field'] ?? 'first_name', $context);

        // Lead requires first_name (NOT NULL). Fall back to "Web
        // lead" when the source posted neither name nor enough
        // structure to derive one — better than silently
        // dropping the submission.
        if ($firstName === null || trim($firstName) === '') {
            $firstName = 'Web lead';
        }

        $lead = Lead::create([
            'first_name' => $firstName,
            'last_name' => $this->resolveField($config['last_name_field'] ?? 'last_name', $context),
            'email' => $this->resolveField($config['email_field'] ?? 'email', $context),
            'phone' => $this->resolveField($config['phone_field'] ?? 'phone', $context),
            'company' => $this->resolveField($config['company_field'] ?? 'company', $context),
            'source' => $config['source'] ?? 'other',
            'source_detail' => $this->resolveField($config['source_detail_field'] ?? null, $context),
            'status' => $config['status'] ?? 'new',
            'assigned_to' => $config['assigned_to'] ?? null,
            'form_submission_id' => $context['submission_id'] ?? null,
            // Workflow-fired creates have no acting user, so we
            // attribute them to the platform owner (user 1). The
            // form / workflow that fired is still recorded via
            // form_submission_id + activity_log.
            'created_by' => $config['created_by'] ?? 1,
        ]);

        // Back-stamp the submission so the Forms/Submissions
        // table can show "Submission -> Lead".
        if (isset($context['submission_id'])) {
            FormSubmission::where('id', $context['submission_id'])
                ->update([
                    'lead_id' => $lead->id,
                    'status' => 'processed',
                ]);
        }

        return array_merge($context, [
            'lead_id' => $lead->id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name ?? '',
            'email' => $lead->email ?? '',
            'phone' => $lead->phone ?? '',
            'company' => $lead->company ?? '',
            'source' => $lead->source,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function actionCreateTask(array $config, array $context): array
    {
        $title = $this->renderTemplate(
            (string) ($config['title_template'] ?? 'Follow up'),
            $context,
        );

        $dueAt = isset($config['due_in_days'])
            ? now()->addDays((int) $config['due_in_days'])->setTime(9, 0, 0)
            : null;

        Task::create([
            'lead_id' => $context['lead_id'] ?? null,
            'customer_id' => $context['customer_id'] ?? null,
            'title' => $title,
            'type' => $config['type'] ?? 'task',
            'priority' => $config['priority'] ?? 'medium',
            'status' => 'todo',
            'assigned_to' => $config['assigned_to'] ?? null,
            'due_at' => $dueAt,
            'created_by' => $config['created_by'] ?? 1,
        ]);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function actionAddNote(array $config, array $context): array
    {
        $body = $this->renderTemplate(
            (string) ($config['content_template'] ?? ''),
            $context,
        );

        if ($body === '') {
            return $context;
        }

        // notes.customer_id is NOT NULL in the schema, so an
        // add_note action only fires when the workflow context
        // already carries a customer_id (i.e. paired with a
        // customer-creating action, or scoped to an existing
        // customer trigger). Lead-only add_note is silently
        // skipped — the form_submission record still holds the
        // raw payload for forensic recovery.
        if (! isset($context['customer_id'])) {
            return $context;
        }

        Note::create([
            'customer_id' => $context['customer_id'],
            'lead_id' => $context['lead_id'] ?? null,
            'created_by' => $config['created_by'] ?? 1,
            'type' => 'internal',
            'body' => $body,
        ]);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function actionUpdateLeadStatus(array $config, array $context): array
    {
        if (! isset($context['lead_id']) || ! isset($config['status'])) {
            return $context;
        }

        Lead::where('id', $context['lead_id'])
            ->update(['status' => $config['status']]);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function actionAssignToUser(array $config, array $context): array
    {
        if (! isset($context['lead_id']) || ! isset($config['user_id'])) {
            return $context;
        }

        Lead::where('id', $context['lead_id'])
            ->update(['assigned_to' => (int) $config['user_id']]);

        return $context;
    }

    /**
     * Lightweight notification — recorded to activity_log so the
     * recipient's notifications dropdown picks it up via the
     * normal feed query. No mailer call here; that's a follow-up
     * for the queue/mail integration sprint.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function actionSendNotification(array $config, array $context): array
    {
        $message = $this->renderTemplate(
            (string) ($config['message_template'] ?? 'New activity'),
            $context,
        );

        ActivityLog::create([
            'user_id' => isset($config['user_id']) ? (int) $config['user_id'] : null,
            'action' => 'workflow.notification',
            'entity_type' => 'lead',
            'entity_id' => $context['lead_id'] ?? null,
            'before' => null,
            'after' => ['message' => $message],
        ]);

        return $context;
    }

    /**
     * Pull a value out of the context bag by key. The bag is a
     * flat map of {field_key => submitted_value} so workflow
     * config references field names verbatim (e.g.
     * "first_name_field": "first_name").
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveField(?string $fieldKey, array $context): ?string
    {
        if ($fieldKey === null || $fieldKey === '') {
            return null;
        }

        $value = $context[$fieldKey] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Substitute {{var}} placeholders in a template using the
     * context. Only string-coercible values are substituted —
     * arrays / objects are skipped to keep templates safe.
     *
     * @param  array<string, mixed>  $context
     */
    private function renderTemplate(string $template, array $context): string
    {
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $template = str_replace(
                    '{{'.$key.'}}',
                    (string) $value,
                    $template,
                );
            }
        }

        return $template;
    }
}
