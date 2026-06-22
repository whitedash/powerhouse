<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use App\Services\WorkflowConditionEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD for workflows. The runtime is in App\Services\WorkflowEngine —
 * this controller just shapes the rows the engine reads.
 *
 * Each workflow has a single trigger_type + trigger_config and an
 * ordered list of actions. The editor (Workflows/Index.vue) renders
 * action forms whose shape varies with action_type — we accept all
 * config keys here and let the engine's per-action handler pick out
 * the ones it understands.
 */
class WorkflowController extends Controller
{
    private const TRIGGER_TYPES = [
        'form_submitted', 'webhook_received',
        'lead_created', 'lead_status_changed', 'manual',
    ];

    private const ACTION_TYPES = [
        'create_lead', 'update_lead_status', 'create_task', 'create_ticket',
        'assign_to_user', 'add_note', 'send_notification',
        'add_to_group', 'webhook_outbound', 'send_email',
    ];

    public function index(): Response
    {
        $workflows = Workflow::query()
            ->with(['actions', 'createdBy:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Workflow $w): array => $this->mapWorkflow($w));

        // Sidebar pickers in the editor: which forms can fire
        // form_submitted, which staff can be assigned, etc. Each form also
        // carries its field_keys so the builder's placeholder reference can
        // show the real {{keys}} available for the selected form.
        $forms = Form::query()
            ->with('fields')
            ->select(['id', 'name', 'slug', 'status'])
            ->orderBy('name')
            ->get()
            ->map(fn (Form $f): array => [
                'id' => $f->id,
                'name' => $f->name,
                'slug' => $f->slug,
                'status' => $f->status,
                'fields' => $f->fields->map(fn (FormField $field): array => [
                    'key' => $field->field_key,
                    'label' => $field->label,
                    // Type lets the action-parameter binding picker filter to
                    // compatible fields (datetime for due dates, email for
                    // recipients). The conditions picker ignores it.
                    'type' => $field->type,
                ])->values(),
            ]);

        $staff = User::query()
            ->whereIn('role', ['super_admin', 'admin', 'staff'])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
            ]);

        return Inertia::render('Internal/Workflows/Index', [
            'workflows' => $workflows,
            'forms' => $forms,
            'staff' => $staff,
            'trigger_types' => self::TRIGGER_TYPES,
            'action_types' => self::ACTION_TYPES,
            // Single-sourced with the validation allowlist so the builder's
            // operator select can't drift from WorkflowConditionEvaluator.
            'operators' => WorkflowConditionEvaluator::OPERATORS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);

        DB::transaction(function () use ($data, $request): void {
            $workflow = Workflow::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'trigger_type' => $data['trigger_type'],
                'trigger_config' => $data['trigger_config'] ?? null,
                'conditions' => $data['conditions'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['actions'] ?? [] as $i => $action) {
                WorkflowAction::create([
                    'workflow_id' => $workflow->id,
                    'action_type' => $action['action_type'],
                    // Persist the polymorphic config from RAW input, not validated():
                    // the per-index rules in validatePayload() type-check known
                    // subkeys but, as child rules under actions.*.config, would trip
                    // excludeUnvalidatedArrayKeys and prune any engine-read key that
                    // has no explicit rule. Raw input keeps the blob intact and
                    // decoupled from WorkflowEngine's reads.
                    'config' => (array) $request->input("actions.{$i}.config", []),
                    'sort_order' => $i,
                ]);
            }

            $this->log($request, 'workflow.created', $workflow->id, after: [
                'name' => $workflow->name,
                'trigger' => $workflow->trigger_type,
                'actions' => count($data['actions'] ?? []),
            ]);
        });

        return back()->with('success', 'Workflow saved.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $workflow = Workflow::findOrFail($id);
        $data = $this->validatePayload($request);

        DB::transaction(function () use ($workflow, $data, $request): void {
            $before = $workflow->only(['name', 'trigger_type', 'is_active']);

            $workflow->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'trigger_type' => $data['trigger_type'],
                'trigger_config' => $data['trigger_config'] ?? null,
                'conditions' => $data['conditions'] ?? null,
            ]);

            // Wipe + recreate actions, same pattern as form_fields.
            // No external row references actions by id, so this is
            // safe and keeps the controller small.
            $workflow->actions()->delete();
            foreach ($data['actions'] ?? [] as $i => $action) {
                WorkflowAction::create([
                    'workflow_id' => $workflow->id,
                    'action_type' => $action['action_type'],
                    // Raw config, not validated() — see store() for why.
                    'config' => (array) $request->input("actions.{$i}.config", []),
                    'sort_order' => $i,
                ]);
            }

            $this->log($request, 'workflow.updated', $workflow->id, $before, [
                'name' => $workflow->name,
                'trigger' => $workflow->trigger_type,
                'actions' => count($data['actions'] ?? []),
            ]);
        });

        return back()->with('success', 'Workflow updated.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $workflow = Workflow::findOrFail($id);

        DB::transaction(function () use ($workflow, $request): void {
            $snapshot = $workflow->only(['id', 'name', 'trigger_type']);
            $workflow->delete();
            $this->log($request, 'workflow.deleted', $snapshot['id'], before: $snapshot);
        });

        return back()->with('success', 'Workflow deleted.');
    }

    /**
     * Quick on/off toggle from the row's switch — separate POST
     * so it doesn't require resubmitting the full editor payload.
     */
    public function toggle(int $id, Request $request): JsonResponse
    {
        $workflow = Workflow::findOrFail($id);

        $workflow->update([
            'is_active' => ! $workflow->is_active,
        ]);

        $this->log($request, 'workflow.toggled', $workflow->id, after: [
            'is_active' => $workflow->is_active,
        ]);

        return response()->json([
            'is_active' => $workflow->is_active,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapWorkflow(Workflow $w): array
    {
        return [
            'id' => $w->id,
            'name' => $w->name,
            'description' => $w->description,
            'is_active' => $w->is_active,
            'trigger_type' => $w->trigger_type,
            'trigger_config' => $w->trigger_config,
            'conditions' => $w->conditions,
            'run_count' => $w->run_count,
            'last_run_at' => $w->last_run_at?->toIso8601String(),
            'created_by' => $w->createdBy->name,
            'actions' => $w->actions->map(fn (WorkflowAction $a): array => [
                'id' => $a->id,
                'action_type' => $a->action_type,
                'config' => $a->config,
                'sort_order' => $a->sort_order,
            ])->values(),
            'actions_count' => $w->actions->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'trigger_type' => ['required', Rule::in(self::TRIGGER_TYPES)],
            'trigger_config' => ['nullable', 'array'],

            'actions' => ['nullable', 'array'],
            'actions.*.action_type' => ['required', Rule::in(self::ACTION_TYPES)],
            'actions.*.config' => ['required', 'array'],

            // Optional field-value conditions gate (WorkflowConditionEvaluator).
            // Every key is ruled explicitly (Approach A) so validated() returns the
            // complete structure — no excludeUnvalidatedArrayKeys prune — and the
            // operator allowlist fires as a clean 422. field_key matches the
            // form_fields.field_key cap (100). Signed off in
            // scripts/audit-validated-keys.allow.
            'conditions' => ['nullable', 'array'],
            'conditions.logic' => ['nullable', Rule::in(['and', 'or'])],
            'conditions.groups' => ['nullable', 'array'],
            'conditions.groups.*.logic' => ['nullable', Rule::in(['and', 'or'])],
            'conditions.groups.*.conditions' => ['nullable', 'array'],
            'conditions.groups.*.conditions.*.field_key' => ['required', 'string', 'max:100'],
            'conditions.groups.*.conditions.*.operator' => ['required', Rule::in(WorkflowConditionEvaluator::OPERATORS)],
            'conditions.groups.*.conditions.*.value' => ['nullable', 'string'],
        ];

        // A few actions have a config contract worth enforcing (the rest read
        // config defensively). Add per-index rules only for the submitted
        // actions of those types — scoped, not a general per-action validator.
        foreach ((array) $request->input('actions', []) as $i => $action) {
            switch ($action['action_type'] ?? null) {
                case 'send_email':
                    $rules["actions.$i.config.subject_template"] = ['required', 'string', 'max:255'];
                    $rules["actions.$i.config.body_template"] = ['required', 'string', 'max:5000'];
                    // Recipient via the value-source descriptor: a fixed address
                    // (source=static) or a context field name (source=field). The
                    // legacy to_mode/to_field/to_address are kept NULLABLE so
                    // pre-binding workflows still validate; the engine reads
                    // recipient_source first and falls back to the legacy keys.
                    $rules["actions.$i.config.recipient_source.source"] = ['nullable', Rule::in(['static', 'field'])];
                    $rules["actions.$i.config.recipient_source.static"] = ['nullable', 'email', 'max:255'];
                    $rules["actions.$i.config.recipient_source.field_key"] = ['nullable', 'string', 'max:100'];
                    $rules["actions.$i.config.to_mode"] = ['nullable', Rule::in(['fixed', 'context_field'])];
                    $rules["actions.$i.config.to_address"] = ['nullable', 'email', 'max:255'];
                    $rules["actions.$i.config.to_field"] = ['nullable', 'string', 'max:255'];

                    break;

                case 'create_task':
                    // Due date via the value-source descriptor: a datetime form
                    // field (source=field) or the relative due_in_days offset
                    // (source=static / no descriptor). due_at is never null.
                    $rules["actions.$i.config.due_at_source.source"] = ['nullable', Rule::in(['static', 'field'])];
                    $rules["actions.$i.config.due_at_source.field_key"] = ['nullable', 'string', 'max:100'];
                    $rules["actions.$i.config.due_in_days"] = ['nullable', 'integer', 'min:0', 'max:3650'];

                    break;

                case 'create_ticket':
                    // message_field is required: an empty resolve makes the
                    // engine handler a silent no-op, so fail at save instead.
                    $rules["actions.$i.config.message_field"] = ['required', 'string', 'max:255'];
                    $rules["actions.$i.config.subject_field"] = ['nullable', 'string', 'max:255'];
                    $rules["actions.$i.config.priority"] = ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])];
                    $rules["actions.$i.config.name_field"] = ['nullable', 'string', 'max:255'];
                    $rules["actions.$i.config.email_field"] = ['nullable', 'string', 'max:255'];
                    $rules["actions.$i.config.phone_field"] = ['nullable', 'string', 'max:255'];
                    $rules["actions.$i.config.source"] = ['nullable', 'string', 'max:255'];

                    break;
            }
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Request $request, string $action, int $entityId, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => $action,
            'entity_type' => 'workflow',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
