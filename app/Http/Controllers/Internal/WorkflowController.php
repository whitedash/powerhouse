<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Form;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
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
        'create_lead', 'update_lead_status', 'create_task',
        'assign_to_user', 'add_note', 'send_notification',
        'add_to_group', 'webhook_outbound',
    ];

    public function index(): Response
    {
        $workflows = Workflow::query()
            ->with(['actions', 'createdBy:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Workflow $w): array => $this->mapWorkflow($w));

        // Sidebar pickers in the editor: which forms can fire
        // form_submitted, which staff can be assigned, etc.
        $forms = Form::query()
            ->select(['id', 'name', 'slug', 'status'])
            ->orderBy('name')
            ->get()
            ->map(fn (Form $f): array => [
                'id' => $f->id,
                'name' => $f->name,
                'slug' => $f->slug,
                'status' => $f->status,
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
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['actions'] ?? [] as $i => $action) {
                WorkflowAction::create([
                    'workflow_id' => $workflow->id,
                    'action_type' => $action['action_type'],
                    'config' => $action['config'] ?? [],
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
            ]);

            // Wipe + recreate actions, same pattern as form_fields.
            // No external row references actions by id, so this is
            // safe and keeps the controller small.
            $workflow->actions()->delete();
            foreach ($data['actions'] ?? [] as $i => $action) {
                WorkflowAction::create([
                    'workflow_id' => $workflow->id,
                    'action_type' => $action['action_type'],
                    'config' => $action['config'] ?? [],
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
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'trigger_type' => ['required', Rule::in(self::TRIGGER_TYPES)],
            'trigger_config' => ['nullable', 'array'],

            'actions' => ['nullable', 'array'],
            'actions.*.action_type' => ['required', Rule::in(self::ACTION_TYPES)],
            'actions.*.config' => ['required', 'array'],
        ]);
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
