<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;

/**
 * Automates the lead pipeline's new -> contacted step off task activity.
 *
 * The rule is type-split because "when did we contact them" differs by
 * activity type (see the lead-status-automation investigation):
 *  - email / note: logged-after-the-fact records that never reliably reach a
 *    'complete' state, so CREATION is the contact moment.
 *  - call / meeting: scheduled to-dos with a real completion, so COMPLETION
 *    is the contact moment (incl. a task created already-complete).
 *  - task (generic): neither — a plain task is not a contact activity.
 *
 * Only ever new -> contacted, only when the task carries a lead_id, and a lead
 * in any other status is left untouched. Inherently loop-safe: Lead has no
 * observer, so the status write cascades nowhere. Runs inside whatever
 * transaction created/updated the task (the model events fire synchronously).
 */
class TaskObserver
{
    /** Contact moment is CREATION for these types. */
    private const CONTACT_ON_CREATE = ['email', 'note'];

    /** Contact moment is COMPLETION for these types. */
    private const CONTACT_ON_COMPLETE = ['call', 'meeting'];

    public function created(Task $task): void
    {
        // email/note: creation is the contact moment. call/meeting created
        // ALREADY complete counts too — updated() never fires on a create, so
        // this is the only place that completion-at-creation is observed.
        $createContact = in_array($task->type, self::CONTACT_ON_CREATE, true);
        $completeAtCreate = in_array($task->type, self::CONTACT_ON_COMPLETE, true)
            && $task->status === 'complete';

        if ($createContact || $completeAtCreate) {
            $this->markLeadContacted($task);
        }
    }

    public function updated(Task $task): void
    {
        // call/meeting transitioning INTO complete. A task created-complete
        // took the created() path above, so exactly one path ever fires.
        if ($task->wasChanged('status')
            && $task->status === 'complete'
            && in_array($task->type, self::CONTACT_ON_COMPLETE, true)) {
            $this->markLeadContacted($task);
        }
    }

    private function markLeadContacted(Task $task): void
    {
        if ($task->lead_id === null) {
            return;
        }

        $lead = Lead::find($task->lead_id);
        if ($lead === null || $lead->status !== 'new') {
            // Only new -> contacted; skip silently for any later/unrelated status.
            return;
        }

        $lead->update(['status' => 'contacted']);

        // Audit row in LeadController::updateStatus's lead.status_changed
        // convention. Attributed to the acting staff user when there is one
        // (a manual completion), else the system actor (CLI / workflow create).
        $actor = auth()->user();
        ActivityLog::create([
            'user_id' => $actor instanceof User ? $actor->id : null,
            'user_role' => $actor instanceof User ? $actor->role : 'system',
            'action' => 'lead.status_changed',
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'before' => null,
            'after' => ['from' => 'new', 'to' => 'contacted', 'via' => 'task_'.$task->type],
        ]);
    }
}
