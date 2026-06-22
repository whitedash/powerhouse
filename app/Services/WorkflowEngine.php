<?php

namespace App\Services;

use App\Mail\WorkflowEmail;
use App\Models\ActivityLog;
use App\Models\FormSubmission;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
     * Emails queued by send_email actions during a single workflow's run,
     * flushed only AFTER that workflow's transaction commits (see trigger()).
     * Reset per workflow so a rolled-back run never sends.
     *
     * @var list<array{to: string, subject: string, body: string}>
     */
    private array $pendingEmails = [];

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

            // Optional field-value conditions gate. NULL/empty conditions match
            // (no gating); otherwise the stored AND/OR logic is evaluated against
            // the payload before any side effect or transaction.
            if (! $this->matchesConditions($workflow, $payload)) {
                continue;
            }

            // Reset per workflow: send_email actions push onto this list; it is
            // flushed only on a clean commit below, so a throwing run sends nothing.
            $this->pendingEmails = [];

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

                // Side-effecting sends fire ONLY after the transaction above
                // commits — a later action throwing rolls the row back and these
                // never go out (mirrors TicketIntakeService's after-commit send).
                $this->flushPendingEmails($workflow);
            } catch (\Throwable $e) {
                Log::error('Workflow failed', [
                    'workflow_id' => $workflow->id,
                    'trigger' => $triggerType,
                    'error' => $e->getMessage(),
                ]);
                // Surface to the exception handler / Sentry too — a swallowed
                // throw here silently drops the lead/ticket the workflow would
                // have created.
                report($e);
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
     * Optional field-value conditions gate, evaluated after trigger_config and
     * before the action transaction. NULL/empty conditions => matches (no gating).
     *
     * @param  array<string, mixed>  $payload
     */
    private function matchesConditions(Workflow $workflow, array $payload): bool
    {
        return (new WorkflowConditionEvaluator())->matches($workflow->conditions, $payload);
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
            'create_ticket' => $this->actionCreateTicket($action->config, $context),
            'create_task' => $this->actionCreateTask($action->config, $context),
            'add_note' => $this->actionAddNote($action->config, $context),
            'update_lead_status' => $this->actionUpdateLeadStatus($action->config, $context),
            'send_notification' => $this->actionSendNotification($action->config, $context),
            'assign_to_user' => $this->actionAssignToUser($action->config, $context),
            'send_email' => $this->actionSendEmail($action->config, $context),
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

        // leads.created_by is NOT NULL (FK→users). Resolve to a guaranteed-real
        // user — the configured one if it exists, else the first super_admin —
        // never a magic "1" that may not exist (which would FK-fail and, caught
        // by trigger(), silently drop the lead). If none resolves, skip + report
        // rather than throw.
        $createdBy = $this->resolveWorkflowActorId(
            isset($config['created_by']) ? (int) $config['created_by'] : null
        );
        if ($createdBy === null) {
            report(new \RuntimeException('actionCreateLead: no valid created_by (config user or super_admin) — lead not created.'));

            return $context;
        }

        $lead = Lead::create([
            'first_name' => $firstName,
            'last_name' => $this->resolveField($config['last_name_field'] ?? 'last_name', $context),
            'email' => $this->resolveField($config['email_field'] ?? 'email', $context),
            'phone' => $this->resolveField($config['phone_field'] ?? 'phone', $context),
            'company' => $this->resolveField($config['company_field'] ?? 'company', $context),
            'source' => $config['source'] ?? 'other',
            'source_detail' => $this->resolveField($config['source_detail_field'] ?? null, $context),
            // Referral attribution captured at form submission (null when
            // the submission carried no ?ref / wd_ref). Existing behaviour
            // is unchanged when these keys are absent.
            'referrer_id' => $context['referrer_id'] ?? null,
            'referral_code' => $context['referral_code'] ?? null,
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
    /**
     * Create a support ticket from a form submission, via the shared
     * TicketIntakeService (same path as the public /support form). Skips
     * silently when there's no message body to file.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    /**
     * A guaranteed-real users.id for workflow-fired records that need a
     * NOT-NULL created_by: the configured id if it exists, else the first
     * super_admin. Null only when no super_admin exists at all.
     */
    private function resolveWorkflowActorId(?int $configured): ?int
    {
        if ($configured !== null && User::whereKey($configured)->exists()) {
            return $configured;
        }

        return User::where('role', 'super_admin')->orderBy('id')->value('id');
    }

    private function actionCreateTicket(array $config, array $context): array
    {
        $subject = $this->resolveField($config['subject_field'] ?? 'subject', $context) ?: 'Support request';
        $message = $this->resolveField($config['message_field'] ?? 'message', $context);

        if ($message === null || trim($message) === '') {
            return $context;
        }

        $ticket = app(TicketIntakeService::class)->create([
            'subject' => $subject,
            'message' => $message,
            'priority' => $config['priority'] ?? 'medium',
            'guest_name' => $this->resolveField($config['name_field'] ?? 'name', $context),
            'guest_email' => $this->resolveField($config['email_field'] ?? 'email', $context),
            'guest_phone' => $this->resolveField($config['phone_field'] ?? 'phone', $context),
        ], $config['source'] ?? 'workflow');

        return array_merge($context, ['ticket_id' => $ticket->id]);
    }

    private function actionCreateTask(array $config, array $context): array
    {
        $title = $this->renderTemplate(
            (string) ($config['title_template'] ?? 'Follow up'),
            $context,
        );

        // due_at is mandatory on tasks (never null). Default window: +due_in_days
        // (def 3) at 09:00. An optional value source instead binds due_at to a
        // datetime form field; a field that is empty, absent, or fails the strict
        // 'Y-m-d\TH:i' parse falls back to the offset. Parsing NEVER throws — a
        // throw here would silently roll the whole workflow run back.
        $dueAt = now()->addDays((int) ($config['due_in_days'] ?? 3))->setTime(9, 0, 0);

        $source = $config['due_at_source'] ?? null;
        if (is_array($source) && ($source['source'] ?? null) === 'field') {
            $parsed = $this->parseDateTimeLocal($this->resolveValueSource($source, $context));
            if ($parsed !== null) {
                $dueAt = $parsed;
            }
        }

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
     * Queue a builder-configured email. Recipient is either a fixed address
     * (defaulting to the support inbox) or pulled from the context by a
     * config-named field; subject/body are {{field}} templates. The send is
     * NOT performed here — it is deferred to flushPendingEmails() after the
     * workflow transaction commits, so a later action throwing never leaves a
     * sent email behind a rolled-back run.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function actionSendEmail(array $config, array $context): array
    {
        // Recipient via the value-source descriptor when present; otherwise the
        // legacy to_mode/to_field/to_address (existing pre-binding workflows).
        $source = $config['recipient_source'] ?? null;
        if (is_array($source)) {
            $recipient = $this->resolveValueSource($source, $context);
            // A static address left blank defaults to the support inbox (mirrors
            // the legacy fixed mode); a field source resolving to null is a no-op.
            if (($source['source'] ?? null) !== 'field' && ($recipient === null || trim($recipient) === '')) {
                $recipient = config('support.notify_email');
            }
        } else {
            $recipient = ($config['to_mode'] ?? 'fixed') === 'context_field'
                ? $this->resolveField($config['to_field'] ?? null, $context)
                : ($config['to_address'] ?? config('support.notify_email'));
        }

        // No deliverable address → skip silently (same defensive no-op as the
        // other actions). The run still commits; nothing is queued.
        if ($recipient === null || trim((string) $recipient) === '') {
            return $context;
        }

        $this->pendingEmails[] = [
            'to' => (string) $recipient,
            'subject' => $this->renderTemplate((string) ($config['subject_template'] ?? ''), $context),
            'body' => $this->renderTemplate((string) ($config['body_template'] ?? ''), $context),
        ];

        return $context;
    }

    /**
     * Send the emails queued by send_email actions during this workflow's run.
     * Called only after a clean commit. Each send is isolated: a mail failure
     * is reported and skipped, never breaking sibling sends or the request.
     */
    private function flushPendingEmails(Workflow $workflow): void
    {
        foreach ($this->pendingEmails as $mail) {
            try {
                Mail::to($mail['to'])->send(new WorkflowEmail($mail['subject'], $mail['body']));

                ActivityLog::create([
                    'user_id' => null,
                    'action' => 'workflow.email_sent',
                    'entity_type' => 'workflow',
                    'entity_id' => $workflow->id,
                    'before' => null,
                    // Recipient + subject only — no body PII in the audit trail.
                    'after' => [
                        'recipient' => $mail['to'],
                        'subject' => $mail['subject'],
                    ],
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->pendingEmails = [];
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
     * Resolve a per-parameter value source: a static literal, or a form-field
     * value via resolveField(). Descriptor shape:
     *   { source: 'static'|'field', static: <literal>, field_key: <key> }.
     * Callers apply any type coercion (e.g. datetime parsing).
     *
     * @param  array<string, mixed>|null  $descriptor
     * @param  array<string, mixed>  $context
     */
    private function resolveValueSource(?array $descriptor, array $context): ?string
    {
        if (! is_array($descriptor)) {
            return null;
        }

        if (($descriptor['source'] ?? null) === 'field') {
            return $this->resolveField($descriptor['field_key'] ?? null, $context);
        }

        $static = $descriptor['static'] ?? null;

        return is_scalar($static) ? (string) $static : null;
    }

    /**
     * Strict-parse the 'Y-m-d\TH:i' string an <input type="datetime-local">
     * posts (no seconds, no timezone). Returns null — NEVER throws — on empty,
     * absent, or malformed input, so a bad bound value is handled by the caller
     * (fallback) rather than aborting the workflow transaction.
     */
    private function parseDateTimeLocal(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d\TH:i', trim($value));
        } catch (\Throwable) {
            return null;
        }

        return $parsed instanceof Carbon ? $parsed : null;
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
