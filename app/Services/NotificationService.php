<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Notifications\LeadAssigned;
use App\Notifications\MilestoneCompleted;
use App\Notifications\ProjectOverdue;
use App\Notifications\ProposalAccepted;
use App\Notifications\SupportTicketAssigned;
use App\Notifications\TaskAssigned;

/**
 * Single entry point for dispatching in-app notifications. Every caller
 * routes through here so the "don't notify yourself" and per-user
 * preference checks live in exactly one place — the notification classes
 * stay dumb data carriers and the controllers stay thin.
 */
class NotificationService
{
    public function notifyTaskAssigned(Task $task, User $assignedBy): void
    {
        if ($task->assigned_to === null) {
            return;
        }

        $user = User::find($task->assigned_to);
        if (! $user || $user->id === $assignedBy->id) {
            return;
        }
        if (! $user->wantsNotification('task_assigned')) {
            return;
        }

        $user->notify(new TaskAssigned($task, $assignedBy));
    }

    public function notifyMilestoneCompleted(Milestone $milestone): void
    {
        /** @var Project|null $project */
        $project = $milestone->project()->with('members')->first();
        if ($project === null) {
            return;
        }

        // Part 2 intent: notify the lead AND every member. The lead may
        // not be in the project_members pivot, so union both and dedupe.
        $recipients = $project->members->all();
        if ($project->project_lead !== null) {
            $lead = User::find($project->project_lead);
            if ($lead) {
                $recipients[] = $lead;
            }
        }

        $seen = [];
        foreach ($recipients as $user) {
            if (isset($seen[$user->id])) {
                continue;
            }
            $seen[$user->id] = true;

            if (! $user->wantsNotification('milestone_completed')) {
                continue;
            }
            $user->notify(new MilestoneCompleted($milestone));
        }
    }

    public function notifyProjectOverdue(Project $project): void
    {
        if ($project->project_lead === null) {
            return;
        }

        $lead = User::find($project->project_lead);
        if (! $lead || ! $lead->wantsNotification('project_overdue')) {
            return;
        }

        $lead->notify(new ProjectOverdue($project));
    }

    public function notifyLeadAssigned(Lead $lead, User $assignedBy): void
    {
        if ($lead->assigned_to === null) {
            return;
        }

        $user = User::find($lead->assigned_to);
        if (! $user || $user->id === $assignedBy->id) {
            return;
        }
        if (! $user->wantsNotification('lead_assigned')) {
            return;
        }

        $user->notify(new LeadAssigned($lead, $assignedBy));
    }

    public function notifySupportAssigned(SupportTicket $ticket, User $assignedBy): void
    {
        if ($ticket->assigned_to === null) {
            return;
        }

        $user = User::find($ticket->assigned_to);
        if (! $user || $user->id === $assignedBy->id) {
            return;
        }
        if (! $user->wantsNotification('support_ticket_assigned')) {
            return;
        }

        $user->notify(new SupportTicketAssigned($ticket, $assignedBy));
    }

    public function notifyProposalAccepted(Proposal $proposal): void
    {
        $creator = User::find($proposal->created_by);
        if (! $creator || ! $creator->wantsNotification('proposal_accepted')) {
            return;
        }

        $creator->notify(new ProposalAccepted($proposal));
    }
}
