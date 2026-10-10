<?php

namespace App\Listeners;

use App\Events\Projects\ProjectAssignedEvent;
use App\Events\Projects\ProjectCompletionRequestedEvent;
use App\Events\Projects\ProjectDeadlineApproachingEvent;
use App\Events\Projects\ProjectDeadlineChangedEvent;
use App\Events\Projects\ProjectFileUploadedEvent;
use App\Events\Projects\ProjectMemberAddedEvent;
use App\Events\Projects\ProjectMemberRemovedEvent;
use App\Events\Projects\ProjectOverdueEvent;
use App\Events\Projects\ProjectStatusChangedEvent;
use App\Models\Notification;
use App\Services\NotificationService;

class ProjectNotificationListener
{
    public function __construct(
        protected NotificationService $notificationService
    ) {
    }

    public function handleProjectAssigned(ProjectAssignedEvent $event): void
    {
        if ($event->teamLead) {
            $this->notificationService->notifyUser(
                (int) $event->teamLead->id,
                'project_lead_assigned',
                'Assigned as Team Lead',
                "You have been assigned as Team Lead for project '{$event->project->name}'.",
                ['project_id' => $event->project->id],
                $event->project->company_id
            );
        }
    }

    public function handleProjectCompletionRequested(ProjectCompletionRequestedEvent $event): void
    {
        $targetUserId = $event->project->manager_id ?? $event->project->created_by_id;
        if ($targetUserId) {
            $this->notificationService->notifyUser(
                (int) $targetUserId,
                'project_completion_requested',
                'Project Completion Requested',
                "Team Lead {$event->actor->name} requested completion for project '{$event->project->name}'.",
                ['project_id' => $event->project->id],
                $event->project->company_id
            );
        }
    }

    public function handleProjectDeadlineApproaching(ProjectDeadlineApproachingEvent $event): void
    {
        $recipients = array_filter(array_unique([$event->project->team_lead_id, $event->project->manager_id]));
        foreach ($recipients as $userId) {
            $alreadySent = Notification::where('user_id', $userId)
                ->where('type', 'project_deadline_approaching')
                ->whereDate('created_at', now()->toDateString())
                ->where('data->project_id', $event->project->id)
                ->exists();

            if (! $alreadySent) {
                $this->notificationService->notifyUser(
                    (int) $userId,
                    'project_deadline_approaching',
                    'Project Deadline Approaching',
                    "Project '{$event->project->name}' deadline is approaching in {$event->daysRemaining} day(s).",
                    ['project_id' => $event->project->id, 'days_remaining' => $event->daysRemaining],
                    $event->project->company_id
                );
            }
        }
    }

    public function handleProjectOverdue(ProjectOverdueEvent $event): void
    {
        $recipients = array_filter(array_unique([$event->project->team_lead_id, $event->project->manager_id]));
        foreach ($recipients as $userId) {
            $alreadySent = Notification::where('user_id', $userId)
                ->where('type', 'project_overdue')
                ->whereDate('created_at', now()->toDateString())
                ->where('data->project_id', $event->project->id)
                ->exists();

            if (! $alreadySent) {
                $this->notificationService->notifyUser(
                    (int) $userId,
                    'project_overdue',
                    'Project Overdue',
                    "Project '{$event->project->name}' is overdue by {$event->daysOverdue} day(s).",
                    ['project_id' => $event->project->id, 'days_overdue' => $event->daysOverdue],
                    $event->project->company_id
                );
            }
        }
    }

    public function handleProjectMemberAdded(ProjectMemberAddedEvent $event): void
    {
        $this->notificationService->notifyUser(
            (int) $event->member->id,
            'project_member_added',
            'Added to Project',
            "You have been added to project '{$event->project->name}' as {$event->projectRole}.",
            ['project_id' => $event->project->id, 'role' => $event->projectRole],
            $event->project->company_id
        );
    }

    public function handleProjectMemberRemoved(ProjectMemberRemovedEvent $event): void
    {
        $this->notificationService->notifyUser(
            (int) $event->member->id,
            'project_member_removed',
            'Removed from Project',
            "You were removed from project '{$event->project->name}'.",
            ['project_id' => $event->project->id],
            $event->project->company_id
        );
    }

    public function handleProjectStatusChanged(ProjectStatusChangedEvent $event): void
    {
        $recipients = array_filter(array_unique([$event->project->team_lead_id, $event->project->manager_id]));
        foreach ($recipients as $userId) {
            if ((int) $userId !== (int) $event->actor->id) {
                $this->notificationService->notifyUser(
                    (int) $userId,
                    'project_status_changed',
                    'Project Status Updated',
                    "Project '{$event->project->name}' status changed to '{$event->newStatus}'.",
                    ['project_id' => $event->project->id, 'status' => $event->newStatus],
                    $event->project->company_id
                );
            }
        }
    }

    public function handleProjectFileUploaded(ProjectFileUploadedEvent $event): void
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
            $this->notificationService->notifyUser(
                (int) $userId,
                'project_file_uploaded',
                "New file in {$project->name}",
                "{$uploader->name} uploaded file '{$event->file->file_name}'.",
                [
                    'project_id' => $project->id,
                    'file_id'    => $event->file->id,
                ],
                $project->company_id
            );
        }
    }
}
