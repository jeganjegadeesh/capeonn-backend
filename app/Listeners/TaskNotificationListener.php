<?php

namespace App\Listeners;

use App\Events\Tasks\TaskAssignedEvent;
use App\Events\Tasks\TaskChangesRequestedEvent;
use App\Events\Tasks\TaskCompletedEvent;
use App\Events\Tasks\TaskDueSoonEvent;
use App\Events\Tasks\TaskOverdueEvent;
use App\Events\Tasks\TaskReopenedEvent;
use App\Events\Tasks\TaskSubmittedForReviewEvent;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Support\Str;

class TaskNotificationListener
{
    public function __construct(
        protected NotificationService $notificationService
    ) {
    }

    public function handleTaskAssigned(TaskAssignedEvent $event): void
    {
        if ($event->assignee && (int) $event->assignee->id !== (int) $event->actor->id) {
            $title = $event->previousAssignee ? 'Task Reassigned' : 'Task Assigned';
            $msg = $event->previousAssignee
                ? "Task '{$event->task->title}' was reassigned to you by {$event->actor->name}."
                : "You were assigned to task '{$event->task->title}' by {$event->actor->name}.";

            $this->notificationService->notifyUser(
                (int) $event->assignee->id,
                'task_assigned',
                $title,
                $msg,
                ['task_id' => $event->task->id, 'project_id' => $event->task->project_id],
                $event->task->company_id
            );
        }

        // Also notify the previous assignee that the task was reassigned
        if ($event->previousAssignee && (int) $event->previousAssignee->id !== (int) $event->actor->id) {
            $this->notificationService->notifyUser(
                (int) $event->previousAssignee->id,
                'task_reassigned',
                'Task Handover / Reassigned',
                "Task '{$event->task->title}' was reassigned by {$event->actor->name}." . ($event->reason ? " Reason: {$event->reason}" : ''),
                ['task_id' => $event->task->id, 'project_id' => $event->task->project_id],
                $event->task->company_id
            );
        }
    }

    public function handleTaskSubmittedForReview(TaskSubmittedForReviewEvent $event): void
    {
        $project = $event->task->project;
        $recipients = array_filter(array_unique([$project->team_lead_id, $project->manager_id]));

        foreach ($recipients as $userId) {
            if ((int) $userId !== (int) $event->actor->id) {
                $this->notificationService->notifyUser(
                    (int) $userId,
                    'task_review',
                    'Task Ready for Review',
                    "Task '{$event->task->title}' was submitted for review by {$event->actor->name}.",
                    ['task_id' => $event->task->id, 'project_id' => $event->task->project_id],
                    $event->task->company_id
                );
            }
        }
    }

    public function handleTaskChangesRequested(TaskChangesRequestedEvent $event): void
    {
        if ($event->task->assigned_to_id && (int) $event->task->assigned_to_id !== (int) $event->actor->id) {
            $this->notificationService->notifyUser(
                (int) $event->task->assigned_to_id,
                'task_changes_requested',
                'Changes Requested on Task',
                "Changes were requested on task '{$event->task->title}': {$event->reason}",
                ['task_id' => $event->task->id, 'reason' => $event->reason, 'project_id' => $event->task->project_id],
                $event->task->company_id
            );
        }
    }

    public function handleTaskCompleted(TaskCompletedEvent $event): void
    {
        $recipients = array_filter(array_unique([
            $event->task->assigned_to_id,
            $event->task->submitted_by_id,
            $event->task->project->team_lead_id,
        ]));

        foreach ($recipients as $userId) {
            if ((int) $userId !== (int) $event->actor->id) {
                $this->notificationService->notifyUser(
                    (int) $userId,
                    'task_completed',
                    'Task Completed',
                    "Task '{$event->task->title}' was approved and completed by {$event->actor->name}.",
                    ['task_id' => $event->task->id, 'project_id' => $event->task->project_id],
                    $event->task->company_id
                );
            }
        }
    }

    public function handleTaskReopened(TaskReopenedEvent $event): void
    {
        if ($event->task->assigned_to_id && (int) $event->task->assigned_to_id !== (int) $event->actor->id) {
            $this->notificationService->notifyUser(
                (int) $event->task->assigned_to_id,
                'task_reopened',
                'Task Reopened',
                "Task '{$event->task->title}' was reopened: {$event->reason}",
                ['task_id' => $event->task->id, 'reason' => $event->reason, 'project_id' => $event->task->project_id],
                $event->task->company_id
            );
        }
    }

    public function handleTaskOverdue(TaskOverdueEvent $event): void
    {
        $recipients = array_filter(array_unique([
            $event->task->assigned_to_id,
            $event->task->project->team_lead_id,
        ]));

        foreach ($recipients as $userId) {
            // Deduplicate: check if overdue notification was already sent today for this task
            $alreadySent = Notification::where('user_id', $userId)
                ->where('type', 'task_overdue')
                ->whereDate('created_at', now()->toDateString())
                ->where('data->task_id', $event->task->id)
                ->exists();

            if (! $alreadySent) {
                $this->notificationService->notifyUser(
                    (int) $userId,
                    'task_overdue',
                    'Task Overdue',
                    "Task '{$event->task->title}' is overdue by {$event->daysOverdue} day(s).",
                    ['task_id' => $event->task->id, 'days_overdue' => $event->daysOverdue, 'project_id' => $event->task->project_id],
                    $event->task->company_id
                );
            }
        }
    }

    public function handleTaskDueSoon(TaskDueSoonEvent $event): void
    {
        if ($event->task->assigned_to_id) {
            $alreadySent = Notification::where('user_id', $event->task->assigned_to_id)
                ->where('type', 'task_due_soon')
                ->whereDate('created_at', now()->toDateString())
                ->where('data->task_id', $event->task->id)
                ->exists();

            if (! $alreadySent) {
                $this->notificationService->notifyUser(
                    (int) $event->task->assigned_to_id,
                    'task_due_soon',
                    'Task Due Soon',
                    "Task '{$event->task->title}' is due in {$event->daysRemaining} day(s).",
                    ['task_id' => $event->task->id, 'days_remaining' => $event->daysRemaining, 'project_id' => $event->task->project_id],
                    $event->task->company_id
                );
            }
        }
    }

    public function handleTaskCommentCreated(\App\Events\Tasks\TaskCommentCreatedEvent $event): void
    {
        $comment = $event->comment;
        $task = $comment->task;
        if (! $task) return;

        $project = $task->project;
        $recipients = collect();

        if ($task->assigned_to_id && (int) $task->assigned_to_id !== (int) $event->actor->id) {
            $recipients->push((int) $task->assigned_to_id);
        }

        if ($project && $project->team_lead_id && (int) $project->team_lead_id !== (int) $event->actor->id) {
            $recipients->push((int) $project->team_lead_id);
        }

        if ($project && $project->manager_id && (int) $project->manager_id !== (int) $event->actor->id) {
            $recipients->push((int) $project->manager_id);
        }

        if ($comment->parent_id && $comment->parent && (int) $comment->parent->user_id !== (int) $event->actor->id) {
            $recipients->push((int) $comment->parent->user_id);
        }

        foreach ($comment->mentions as $mentionedUser) {
            if ((int) $mentionedUser->id !== (int) $event->actor->id) {
                $recipients->push((int) $mentionedUser->id);
            }
        }

        $recipients = $recipients->unique()->values();
        $preview = Str::limit(strip_tags((string) $comment->comment), 80);

        foreach ($recipients as $userId) {
            $this->notificationService->notifyUser(
                (int) $userId,
                'task_comment',
                "New comment on '{$task->title}'",
                "{$event->actor->name}: {$preview}",
                [
                    'task_id'    => $task->id,
                    'comment_id' => $comment->id,
                    'project_id' => $task->project_id,
                ],
                $task->company_id
            );
        }
    }
}
