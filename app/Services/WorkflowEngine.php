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
use App\Models\WorkflowRun;
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
     * Valid leads.status values, gating the update_lead_status action.
     * Mirrors LeadController::STATUSES and the leads.status ENUM
     * (SCHEMA.md) — there is no shared App\Enums\LeadStatus yet, so this
     * must be kept in lockstep with the column if the enum ever widens.
     *
     * @var list<string>
     */
    private const LEAD_STATUSES = [
        'new', 'contacted', 'qualified', 'proposal',
        'negotiation', 'won', 'lost', 'unresponsive',
    ];

    /**
     * Runaway-cascade backstop. A workflow action re-entering trigger()
     * (none does today) can only recurse this many levels before the engine
     * aborts rather than descending further. The id-set below already blocks
     * the realistic A→B→A loop at first re-entry; this cap catches a
     * pathological chain of DISTINCT workflows that the id-set wouldn't.
     */
    private const MAX_TRIGGER_DEPTH = 10;

    /**
     * Loop guard. Ids of workflows already fired within the CURRENT top-level
     * triggering event — a workflow appears at most once, so an action that
     * (in future) re-triggers a workflow already run in this event is skipped
     * instead of looping. Seeded empty on the outermost trigger() call and
     * left intact through any nested calls, so sequential top-level triggers
     * each start clean while a nested trigger shares the outer event's set.
     *
     * @var array<int, true>
     */
    private array $firedWorkflowIds = [];

    /**
     * Re-entrancy depth for trigger(). 0 = not currently triggering; the
     * outermost call runs at depth 1. Drives both the id-set reset (only at
     * depth 0) and the MAX_TRIGGER_DEPTH backstop.
     */
    private int $triggerDepth = 0;

    /**
     * Emails queued by send_email actions during a single workflow's run,
     * flushed only AFTER that workflow's transaction commits (see trigger()).
     * Reset per workflow so a rolled-back run never sends.
     *
     * @var list<array{to: string, subject: string, body: string}>
     */
    private array $pendingEmails = [];

    /**
     * Set by an action handler at each silent no-op (no resolvable actor, no
     * customer_id, invalid status, blank recipient, …) to name WHY it skipped.
     * Reset to null before every executeAction() call; the run loop reads it
     * afterwards to record the action's outcome as skipped-with-reason instead
     * of letting the no-op vanish. Null after a handler that actually acted.
     */
    private ?string $actionSkipReason = null;

    /**
     * Fire every active workflow that matches ($triggerType, $payload).
     *
     * @param  array<string, mixed>  $payload
     */
    public function trigger(string $triggerType, array $payload, ?int $triggerEntityId = null): void
    {
        // Loop guard (in-memory, per triggering event). The id-set is seeded
        // ONLY on the outermost entry so it resets between sequential top-level
        // triggers while any future nested trigger() shares the outer set.
        if ($this->triggerDepth === 0) {
            $this->firedWorkflowIds = [];
        }

        // Backstop before descending: a depth already at the cap means a
        // runaway re-entry chain — log/report and abort rather than recurse.
        // Unreachable today (no action re-enters the engine); the guard is
        // here so the four incoming internal trigger points can't regress it.
        if ($this->triggerDepth >= self::MAX_TRIGGER_DEPTH) {
            $error = new \RuntimeException(sprintf(
                'WorkflowEngine max trigger depth (%d) exceeded for trigger "%s" — aborting to prevent a runaway cascade.',
                self::MAX_TRIGGER_DEPTH,
                $triggerType,
            ));
            Log::error('Workflow trigger depth cap exceeded', [
                'trigger' => $triggerType,
                'depth' => $this->triggerDepth,
            ]);
            report($error);

            return;
        }

        $this->triggerDepth++;

        // finally so the depth (and, at the outer level, the guard's lifetime)
        // unwinds correctly even if a workflow's own error escapes the loop.
        try {
            $this->dispatch($triggerType, $payload, $triggerEntityId);
        } finally {
            $this->triggerDepth--;
        }
    }

    /**
     * Resolve and run every workflow matching ($triggerType, $payload). Split
     * out of trigger() so the loop-guard bookkeeping (depth in/out, id-set
     * seeding) wraps this body without indenting it further.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(string $triggerType, array $payload, ?int $triggerEntityId): void
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

            // Loop guard: a workflow fires at most once per triggering event.
            // Already fired in this event (only possible once an action can
            // re-enter trigger()) => skip, breaking any A→B→A cascade at the
            // first re-entry. Marked BEFORE running so re-entry mid-run is
            // caught too. No-op for today's two external-inbound triggers,
            // whose actions never re-enter the engine.
            if (isset($this->firedWorkflowIds[$workflow->id])) {
                Log::warning('Workflow skipped: already fired in this triggering event', [
                    'workflow_id' => $workflow->id,
                    'trigger' => $triggerType,
                ]);

                // Record the guard's decision as a workflow-level skipped run so
                // the cascade that WAS prevented is visible in the ledger, not
                // only in the framework log. No actions were run → actions null.
                $this->recordRun(
                    $workflow,
                    $triggerType,
                    $triggerEntityId,
                    status: 'skipped',
                    error: 'Loop guard: workflow already fired in this triggering event',
                    durationMs: null,
                    contextSummary: null,
                    actions: null,
                );

                continue;
            }
            $this->firedWorkflowIds[$workflow->id] = true;

            // Reset per workflow: send_email actions push onto this list; it is
            // flushed only on a clean commit below, so a throwing run sends nothing.
            $this->pendingEmails = [];

            // Audit accumulators. These are plain PHP variables (memory), so
            // they SURVIVE the inner transaction's rollback — the failed run's
            // per-action outcomes are still available to record afterwards.
            $runStartedAt = microtime(true);
            $actionOutcomes = [];
            $contextSummary = null;
            $status = 'succeeded';
            $runError = null;

            try {
                DB::transaction(function () use ($workflow, $payload, $triggerType, $triggerEntityId, &$actionOutcomes, &$contextSummary): void {
                    $context = $payload;

                    // lead_id is an ENGINE-INTERNAL handoff key — only
                    // actionCreateLead may set it (see its return merge below).
                    // The public trigger call sites build $payload from the raw
                    // request body (FormController $request->except(...),
                    // WebhookController $request->all(), draft data), NOT from
                    // validated input, so a caller-supplied lead_id must never
                    // seed context and let a lead-mutating action (update_lead_status,
                    // create_task) target an unrelated lead. Strip it here; a
                    // legitimate create_lead re-populates it for downstream actions.
                    unset($context['lead_id']);

                    foreach ($workflow->actions as $action) {
                        $actionStartedAt = microtime(true);
                        // Cleared before each handler; a handler that no-ops sets
                        // it (see markSkip) so we can record WHY it skipped.
                        $this->resetActionSkip();

                        try {
                            $context = $this->executeAction($action, $context);
                            $skipReason = $this->actionSkipReason;
                            $actionOutcomes[] = $this->actionOutcome(
                                $action,
                                $actionStartedAt,
                                $skipReason !== null ? 'skipped' : 'ran',
                                skipReason: $skipReason,
                            );
                        } catch (\Throwable $e) {
                            $actionOutcomes[] = $this->actionOutcome(
                                $action,
                                $actionStartedAt,
                                'failed',
                                error: $e->getMessage(),
                            );
                            // Make the swallowed failure observable with the culprit
                            // action's identity, then re-throw so the whole-workflow
                            // rollback still applies (one bad action voids its run —
                            // this preserves the send_email after-commit guarantee).
                            Log::warning('Workflow action failed', [
                                'workflow_id' => $workflow->id,
                                'action_id' => $action->id,
                                'action_type' => $action->action_type,
                                'error' => $e->getMessage(),
                            ]);

                            throw $e;
                        }
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

                    $contextSummary = $this->contextSummary($context);
                });

                // Side-effecting sends fire ONLY after the transaction above
                // commits — a later action throwing rolls the row back and these
                // never go out (mirrors TicketIntakeService's after-commit send).
                $this->flushPendingEmails($workflow);
            } catch (\Throwable $e) {
                $status = 'failed';
                $runError = $e->getMessage();

                Log::error('Workflow failed', [
                    'workflow_id' => $workflow->id,
                    'trigger' => $triggerType,
                    'error' => $e->getMessage(),
                ]);
                // Surface to the exception handler / Sentry too — a swallowed
                // throw here silently drops the lead/ticket the workflow would
                // have created.
                report($e);
            } finally {
                // Written OUTSIDE the per-workflow transaction above: on a failed
                // run that transaction has already rolled back, so this ledger
                // write lands in the caller's OUTER transaction (or autocommits
                // when there is none) rather than being rolled back with the run.
                // This is the core fix — failed runs now leave a durable record.
                //
                // DURABILITY SCOPE (deliberate, not a bug): this row is durable
                // against the WORKFLOW'S OWN transaction/savepoint rolling back
                // (what the failed-run test proves). It is NOT durable against
                // the CALLER'S outer transaction. Today's callers (FormController,
                // WebhookController, FormService) wrap the whole request —
                // trigger() included — in a DB::transaction, so the inner
                // per-workflow transaction is a savepoint. This write escapes the
                // savepoint but still sits inside the caller's outer transaction:
                // if the caller's own logic throws AFTER trigger() returns, the
                // outer transaction rolls back and takes these rows with it. That
                // is acceptable — if the whole request is voided, "nothing
                // happened" is the correct record. Do not assume workflow_runs
                // survives a caller-level failure; it survives a workflow-level one.
                $this->recordRun(
                    $workflow,
                    $triggerType,
                    $triggerEntityId,
                    status: $status,
                    error: $runError,
                    durationMs: (int) round((microtime(true) - $runStartedAt) * 1000),
                    contextSummary: $contextSummary,
                    actions: $actionOutcomes,
                );
            }
        }
    }

    /**
     * Record one action's outcome for the workflow_runs.actions ledger.
     *
     * @return array{action_id: int, action_type: string, sort_order: int, outcome: string, skip_reason: string|null, error: string|null, duration_ms: int}
     */
    private function actionOutcome(WorkflowAction $action, float $startedAt, string $outcome, ?string $skipReason = null, ?string $error = null): array
    {
        return [
            'action_id' => $action->id,
            'action_type' => $action->action_type,
            'sort_order' => $action->sort_order,
            'outcome' => $outcome, // ran | skipped | failed
            'skip_reason' => $skipReason,
            'error' => $error,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * Called by an action handler at a silent no-op to name the skip. The run
     * loop reads $this->actionSkipReason after each handler and records the
     * action as skipped-with-reason instead of letting the no-op vanish.
     */
    private function markSkip(string $reason): void
    {
        $this->actionSkipReason = $reason;
    }

    /**
     * Clear the skip marker before running an action. A method (not an inline
     * $this->actionSkipReason = null) so static analysis doesn't narrow the
     * property to a constant null across the executeAction() call that follows.
     */
    private function resetActionSkip(): void
    {
        $this->actionSkipReason = null;
    }

    /**
     * The forensically useful ids a run resolved, for workflow_runs.context_summary.
     * Null when the run produced none (so the column stays empty rather than {}).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function contextSummary(array $context): ?array
    {
        $summary = array_filter(
            [
                'lead_id' => $context['lead_id'] ?? null,
                'ticket_id' => $context['ticket_id'] ?? null,
                'customer_id' => $context['customer_id'] ?? null,
                'submission_id' => $context['submission_id'] ?? null,
            ],
            static fn ($v): bool => $v !== null,
        );

        return $summary === [] ? null : $summary;
    }

    /**
     * Append one workflow_runs row. ALWAYS called outside the per-workflow
     * transaction (from the run loop's finally, or the loop-guard skip), so a
     * failed/rolled-back run still leaves a durable record.
     *
     * @param  array<string, mixed>|null  $contextSummary
     * @param  list<array<string, mixed>>|null  $actions
     */
    private function recordRun(Workflow $workflow, string $triggerType, ?int $triggerEntityId, string $status, ?string $error, ?int $durationMs, ?array $contextSummary, ?array $actions): void
    {
        WorkflowRun::create([
            'workflow_id' => $workflow->id,
            'trigger_type' => $triggerType,
            'trigger_entity_id' => $triggerEntityId,
            'status' => $status,
            'error' => $error,
            'duration_ms' => $durationMs,
            'context_summary' => $contextSummary,
            'actions' => $actions,
            'created_at' => now(),
        ]);
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
     * protected (not private) purely as a test seam: no production action
     * re-enters trigger(), so the loop guard's re-entry path is exercised by
     * a test double that overrides this to call $this->trigger() — the only
     * way to drive a genuine same-instance re-entry, since the guard is
     * per-instance state and app() would hand back a fresh engine.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function executeAction(WorkflowAction $action, array $context): array
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
        // by trigger(), silently drop the lead). If none resolves, skip + warn
        // rather than throw (same shape as actionCreateTask).
        $createdBy = $this->resolveWorkflowActorId(
            isset($config['created_by']) ? (int) $config['created_by'] : null
        );
        if ($createdBy === null) {
            Log::warning('Workflow create_lead skipped: no default creator could be resolved', [
                'action_type' => 'create_lead',
            ]);
            $this->markSkip('no_default_creator');

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
            // Workflow-fired creates have no acting user, so they are
            // attributed to the resolved actor (the configured user if it
            // exists, else the first super_admin). The form / workflow that
            // fired is still recorded via form_submission_id + activity_log.
            'created_by' => $createdBy,
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
            $this->markSkip('no_message');

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

        // tasks.assigned_to and tasks.created_by are both NOT NULL (FK→users,
        // RESTRICT). Resolve each through resolveWorkflowActorId so an unset,
        // null ("Unassigned" in the builder), or stale id falls back to the first
        // super_admin — never null or a magic "1" that would FK-fail and be
        // swallowed by trigger(). Resolution happens at run time, so create_task
        // actions already saved with no assignee start working without a migration.
        $assignedTo = $this->resolveWorkflowActorId(
            isset($config['assigned_to']) ? (int) $config['assigned_to'] : null
        );
        $createdBy = $this->resolveWorkflowActorId(
            isset($config['created_by']) ? (int) $config['created_by'] : null
        );

        // No super_admin exists at all (degenerate) → the NOT NULL FKs can't be
        // satisfied. Skip + warn rather than let a null insert throw into the
        // swallow and silently drop the task.
        if ($assignedTo === null || $createdBy === null) {
            Log::warning('Workflow create_task skipped: no default assignee could be resolved', [
                'action_type' => 'create_task',
            ]);
            $this->markSkip('no_default_assignee');

            return $context;
        }

        Task::create([
            'lead_id' => $context['lead_id'] ?? null,
            'customer_id' => $context['customer_id'] ?? null,
            'title' => $title,
            'type' => $config['type'] ?? 'task',
            'priority' => $config['priority'] ?? 'medium',
            'status' => 'todo',
            'assigned_to' => $assignedTo,
            'due_at' => $dueAt,
            'created_by' => $createdBy,
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
            $this->markSkip('empty_body');

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
            $this->markSkip('no_customer');

            return $context;
        }

        // notes.created_by is NOT NULL (FK→users, RESTRICT). Resolve through
        // resolveWorkflowActorId so an unset id falls back to the first
        // super_admin instead of a magic "1" that would FK-fail and be swallowed
        // by trigger(). If none resolves, skip + warn (same shape as create_task).
        $createdBy = $this->resolveWorkflowActorId(
            isset($config['created_by']) ? (int) $config['created_by'] : null
        );
        if ($createdBy === null) {
            Log::warning('Workflow add_note skipped: no default creator could be resolved', [
                'action_type' => 'add_note',
            ]);
            $this->markSkip('no_default_creator');

            return $context;
        }

        Note::create([
            'customer_id' => $context['customer_id'],
            'lead_id' => $context['lead_id'] ?? null,
            'created_by' => $createdBy,
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
        // lead_id here is trusted: trigger() strips any caller-supplied value
        // at the boundary, so it is present only when an earlier create_lead
        // action in THIS run produced it.
        if (! isset($context['lead_id']) || ! isset($config['status'])) {
            $this->markSkip('no_lead_or_status');

            return $context;
        }

        $newStatus = $config['status'];

        // Validate against the real leads.status ENUM before the write — the
        // action config is persisted raw (WorkflowController excludes it from
        // key validation), so an arbitrary string could otherwise reach the
        // column. A bad value is a misconfigured (or crafted) workflow: skip
        // the write and warn rather than void the whole run on a QueryException.
        if (! in_array($newStatus, self::LEAD_STATUSES, true)) {
            Log::warning('Workflow update_lead_status skipped: invalid target status', [
                'lead_id' => $context['lead_id'],
                'status' => $newStatus,
            ]);
            $this->markSkip('invalid_status');

            return $context;
        }

        $lead = Lead::find($context['lead_id']);
        if ($lead === null) {
            $this->markSkip('lead_not_found');

            return $context;
        }

        $oldStatus = $lead->status;
        if ($oldStatus === $newStatus) {
            $this->markSkip('status_unchanged');

            return $context;
        }

        $lead->update(['status' => $newStatus]);

        // Per-lead audit row, matching LeadController::updateStatus's
        // lead.status_changed convention (from/to in `after`). Workflow-fired,
        // so no request user — attributed to the system actor like the
        // workflow.executed row above.
        ActivityLog::create([
            'user_id' => null,
            'user_role' => 'system',
            'action' => 'lead.status_changed',
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'before' => null,
            'after' => ['from' => $oldStatus, 'to' => $newStatus, 'via' => 'workflow'],
        ]);

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
            $this->markSkip('no_lead_or_user');

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

        // No deliverable address → skip (same defensive no-op as the other
        // actions, now recorded). The run still commits; nothing is queued.
        if ($recipient === null || trim((string) $recipient) === '') {
            $this->markSkip('no_recipient');

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
