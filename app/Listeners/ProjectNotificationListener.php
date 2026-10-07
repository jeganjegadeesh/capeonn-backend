<?php

namespace App\Listeners;

use App\Events\Projects\ProjectAssignedEvent;
use App\Events\Projects\ProjectCompletionRequestedEvent;
use App\Events\Projects\ProjectDeadlineApproachingEvent;
use App\Events\Projects\ProjectDeadlineChangedEvent;
use App\Events\Projects\ProjectMemberAddedEvent;
use App\Events\Projects\ProjectMemberRemovedEvent;
use App\Events\Projects\ProjectOverdueEvent;
use App\Events\Projects\ProjectStatusChangedEvent;
use App\Models\Notification;

class ProjectNotificationListener
{
    public function handleProjectAssigned(ProjectAssignedEvent $event): void
    {
        if ($event->teamLead) {
            Notification::create([
                'company_id' => $event->project->company_id,
                'user_id'    => $event->teamLead->id,
                'type'       => 'project_lead_assigned',
                'title'      => 'Assigned as Team Lead',
                'message'    => "You have been assigned as Team Lead for project '{$event->project->name}'.",
                'data'       => ['project_id' => $event->project->id],
            ]);
        }
    }

    public function handleProjectCompletionRequested(ProjectCompletionRequestedEvent $event): void
    {
        $targetUserId = $event->project->manager_id ?? $event->project->created_by_id;
        if ($targetUserId) {
            Notification::create([
                'company_id' => $event->project->company_id,
                'user_id'    => $targetUserId,
                'type'       => 'project_completion_requested',
                'title'      => 'Project Completion Requested',
                'message'    => "Team Lead {$event->actor->name} requested completion for project '{$event->project->name}'.",
                'data'       => ['project_id' => $event->project->id],
            ]);
        }
    }

    public function handleProjectDeadlineApproaching(ProjectDeadlineApproachingEvent $event): void
    {
        $recipients = array_filter(array_unique([$event->project->team_lead_id, $event->project->manager_id]));
        foreach ($recipients as $userId) {
            Notification::create([
                'company_id' => $event->project->company_id,
                'user_id'    => $userId,
                'type'       => 'project_deadline_approaching',
                'title'      => 'Project Deadline Approaching',
                'message'    => "Project '{$event->project->name}' deadline is approaching in {$event->daysRemaining} day(s).",
                'data'       => ['project_id' => $event->project->id, 'days_remaining' => $event->daysRemaining],
            ]);
        }
    }

    public function handleProjectOverdue(ProjectOverdueEvent $event): void
    {
        $recipients = array_filter(array_unique([$event->project->team_lead_id, $event->project->manager_id]));
        foreach ($recipients as $userId) {
            Notification::create([
                'company_id' => $event->project->company_id,
                'user_id'    => $userId,
                'type'       => 'project_overdue',
                'title'      => 'Project Overdue',
                'message'    => "Project '{$event->project->name}' is overdue by {$event->daysOverdue} day(s).",
                'data'       => ['project_id' => $event->project->id, 'days_overdue' => $event->daysOverdue],
            ]);
        }
    }

    public function handleProjectMemberAdded(ProjectMemberAddedEvent $event): void
    {
        Notification::create([
            'company_id' => $event->project->company_id,
            'user_id'    => $event->member->id,
            'type'       => 'project_member_added',
            'title'      => 'Added to Project',
            'message'    => "You have been added to project '{$event->project->name}' as {$event->projectRole}.",
            'data'       => ['project_id' => $event->project->id, 'role' => $event->projectRole],
        ]);
    }

    public function handleProjectMemberRemoved(ProjectMemberRemovedEvent $event): void
    {
        Notification::create([
            'company_id' => $event->project->company_id,
            'user_id'    => $event->member->id,
            'type'       => 'project_member_removed',
            'title'      => 'Removed from Project',
            'message'    => "You were removed from project '{$event->project->name}'.",
            'data'       => ['project_id' => $event->project->id],
        ]);
    }

    public function handleProjectStatusChanged(ProjectStatusChangedEvent $event): void
    {
        $recipients = array_filter(array_unique([$event->project->team_lead_id, $event->project->manager_id]));
        foreach ($recipients as $userId) {
            if ($userId !== $event->actor->id) {
                Notification::create([
                    'company_id' => $event->project->company_id,
                    'user_id'    => $userId,
                    'type'       => 'project_status_changed',
                    'title'      => 'Project Status Updated',
                    'message'    => "Project '{$event->project->name}' status changed to '{$event->newStatus}'.",
                    'data'       => ['project_id' => $event->project->id, 'status' => $event->newStatus],
                ]);
            }
        }
    }

    public function handleProjectFileUploaded(\App\Events\Projects\ProjectFileUploadedEvent $event): void
    {
        $project = $event->file->project;
        $uploader = $event->uploader;
        if (! $project) {
            return;
        }

        $recipients = collect();
        if ($project->team_lead_id) {
            $recipients->push((int) $project->team_lead_id);
        }
        if ($project->manager_id) {
            $recipients->push((int) $project->manager_id);
        }
        $memberIds = $project->members()->pluck('users.id');
        $recipients = $recipients->merge($memberIds)
            ->unique()
            ->filter(fn ($id) => (int) $id !== (int) $uploader->id);

        foreach ($recipients as $userId) {
            Notification::create([
                'company_id' => $project->company_id,
                'user_id'    => $userId,
                'type'       => 'project_file_uploaded',
                'title'      => "New file in {$project->name}",
                'message'    => "{$uploader->name} uploaded file '{$event->file->file_name}'.",
                'data'       => [
                    'project_id' => $project->id,
                    'file_id'    => $event->file->id,
                ],
            ]);
        }
    }
}
