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

class TaskNotificationListener
{
    public function handleTaskAssigned(TaskAssignedEvent $event): void
    {
        if ($event->assignee && (int) $event->assignee->id !== (int) $event->actor->id) {
            $title = $event->previousAssignee ? 'Task Reassigned' : 'Task Assigned';
            $msg = $event->previousAssignee
                ? "Task '{$event->task->title}' was reassigned to you by {$event->actor->name}."
                : "You were assigned to task '{$event->task->title}' by {$event->actor->name}.";

            Notification::create([
                'company_id' => $event->task->company_id,
                'user_id'    => $event->assignee->id,
                'type'       => 'task_assigned',
                'title'      => $title,
                'message'    => $msg,
                'data'       => ['task_id' => $event->task->id, 'project_id' => $event->task->project_id],
            ]);
        }
    }

    public function handleTaskSubmittedForReview(TaskSubmittedForReviewEvent $event): void
    {
        $project = $event->task->project;
        $recipients = array_filter(array_unique([$project->team_lead_id, $project->manager_id]));

        foreach ($recipients as $userId) {
            if ($userId !== $event->actor->id) {
                Notification::create([
                    'company_id' => $event->task->company_id,
                    'user_id'    => $userId,
                    'type'       => 'task_review',
                    'title'      => 'Task Ready for Review',
                    'message'    => "Task '{$event->task->title}' was submitted for review by {$event->actor->name}.",
                    'data'       => ['task_id' => $event->task->id, 'project_id' => $event->task->project_id],
                ]);
            }
        }
    }

    public function handleTaskChangesRequested(TaskChangesRequestedEvent $event): void
    {
        if ($event->task->assigned_to_id && $event->task->assigned_to_id !== $event->actor->id) {
            Notification::create([
                'company_id' => $event->task->company_id,
                'user_id'    => $event->task->assigned_to_id,
                'type'       => 'task_changes_requested',
                'title'      => 'Changes Requested on Task',
                'message'    => "Changes were requested on task '{$event->task->title}': {$event->reason}",
                'data'       => ['task_id' => $event->task->id, 'reason' => $event->reason],
            ]);
        }
    }

    public function handleTaskCompleted(TaskCompletedEvent $event): void
    {
        $recipients = array_filter(array_unique([
            $event->task->assigned_to_id,
            $event->task->project->team_lead_id,
        ]));

        foreach ($recipients as $userId) {
            if ($userId !== $event->actor->id) {
                Notification::create([
                    'company_id' => $event->task->company_id,
                    'user_id'    => $userId,
                    'type'       => 'task_completed',
                    'title'      => 'Task Completed',
                    'message'    => "Task '{$event->task->title}' was approved and completed by {$event->actor->name}.",
                    'data'       => ['task_id' => $event->task->id],
                ]);
            }
        }
    }

    public function handleTaskReopened(TaskReopenedEvent $event): void
    {
        if ($event->task->assigned_to_id && $event->task->assigned_to_id !== $event->actor->id) {
            Notification::create([
                'company_id' => $event->task->company_id,
                'user_id'    => $event->task->assigned_to_id,
                'type'       => 'task_reopened',
                'title'      => 'Task Reopened',
                'message'    => "Task '{$event->task->title}' was reopened: {$event->reason}",
                'data'       => ['task_id' => $event->task->id, 'reason' => $event->reason],
            ]);
        }
    }

    public function handleTaskOverdue(TaskOverdueEvent $event): void
    {
        $recipients = array_filter(array_unique([
            $event->task->assigned_to_id,
            $event->task->project->team_lead_id,
        ]));

        foreach ($recipients as $userId) {
            Notification::create([
                'company_id' => $event->task->company_id,
                'user_id'    => $userId,
                'type'       => 'task_overdue',
                'title'      => 'Task Overdue',
                'message'    => "Task '{$event->task->title}' is overdue by {$event->daysOverdue} day(s).",
                'data'       => ['task_id' => $event->task->id, 'days_overdue' => $event->daysOverdue],
            ]);
        }
    }

    public function handleTaskDueSoon(TaskDueSoonEvent $event): void
    {
        if ($event->task->assigned_to_id) {
            Notification::create([
                'company_id' => $event->task->company_id,
                'user_id'    => $event->task->assigned_to_id,
                'type'       => 'task_due_soon',
                'title'      => 'Task Due Soon',
                'message'    => "Task '{$event->task->title}' is due in {$event->daysRemaining} day(s).",
                'data'       => ['task_id' => $event->task->id, 'days_remaining' => $event->daysRemaining],
            ]);
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

        if ($comment->parent_id && $comment->parent && (int) $comment->parent->user_id !== (int) $event->actor->id) {
            $recipients->push((int) $comment->parent->user_id);
        }

        foreach ($comment->mentions as $mentionedUser) {
            if ((int) $mentionedUser->id !== (int) $event->actor->id) {
                $recipients->push((int) $mentionedUser->id);
            }
        }

        $recipients = $recipients->unique()->values();
        $preview = \Illuminate\Support\Str::limit(strip_tags((string) $comment->comment), 80);

        foreach ($recipients as $userId) {
            Notification::create([
                'company_id' => $task->company_id,
                'user_id'    => $userId,
                'type'       => 'task_comment',
                'title'      => "New comment on '{$task->title}'",
                'message'    => "{$event->actor->name}: {$preview}",
                'data'       => [
                    'task_id'    => $task->id,
                    'comment_id' => $comment->id,
                    'project_id' => $task->project_id,
                ],
            ]);
        }
    }
}
