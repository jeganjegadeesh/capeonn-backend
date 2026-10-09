<?php

namespace App\Services;

use App\Events\Tasks\TaskAssignedEvent;
use App\Events\Tasks\TaskChangesRequestedEvent;
use App\Events\Tasks\TaskCompletedEvent;
use App\Events\Tasks\TaskReopenedEvent;
use App\Events\Tasks\TaskSubmittedForReviewEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskWorkflowService
{
    public function __construct(private AccessControl $access)
    {
    }

    /**
     * Submit task for review.
     */
    public function submitForReview(Task $task, User $actor, ?string $reason = null): Task
    {
        $project = $task->project;
        if (! $project->acceptsWork()) {
            throw ValidationException::withMessages([
                'project' => 'Project is not active and does not accept work.',
            ]);
        }

        return DB::transaction(function () use ($task, $actor, $reason) {
            // Lock row against race conditions
            $lockedTask = Task::where('id', $task->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedTask->status, [Task::STATUS_ASSIGNED, Task::STATUS_IN_PROGRESS, Task::STATUS_CHANGES_REQUIRED], true)) {
                throw ValidationException::withMessages([
                    'status' => "Task cannot be submitted for review from '{$lockedTask->status}' status.",
                ]);
            }

            // Reject if incomplete subtasks exist
            $incompleteSubtasks = $lockedTask->subtasks()
                ->where('status', '!=', Task::STATUS_COMPLETED)
                ->count();

            if ($incompleteSubtasks > 0) {
                throw ValidationException::withMessages([
                    'subtasks' => "Cannot submit task for review while {$incompleteSubtasks} subtask(s) are incomplete.",
                ]);
            }

            // Stop all active timers on this task
            foreach ($lockedTask->timeEntries()->running()->get() as $runningEntry) {
                $runningEntry->stop();
            }

            $oldStatus = $lockedTask->status;
            $lockedTask->update([
                'status'          => Task::STATUS_REVIEW,
                'submitted_by_id' => $actor->id,
                'submitted_at'    => now(),
            ]);

            // Audit activity row
            $lockedTask->project->recordActivity(
                action: 'task_submitted_for_review',
                description: "Task '{$lockedTask->title}' was submitted for review by {$actor->name}.",
                userId: $actor->id,
                field: 'status',
                oldValue: $oldStatus,
                newValue: Task::STATUS_REVIEW,
                reason: $reason,
                taskId: $lockedTask->id,
            );

            // Record review round
            $nextRound = ((int) $lockedTask->reviews()->max('round_number')) + 1;
            TaskReview::create([
                'company_id'      => $lockedTask->company_id,
                'task_id'         => $lockedTask->id,
                'round_number'    => $nextRound,
                'submitted_by_id' => $actor->id,
                'submitted_at'    => now(),
            ]);

            // Dispatch event after commit
            DB::afterCommit(function () use ($lockedTask, $actor, $reason) {
                TaskSubmittedForReviewEvent::dispatch($lockedTask, $actor, $reason);
            });

            return $lockedTask;
        });
    }

    /**
     * Approve task and transition to completed.
     */
    public function approve(Task $task, User $actor, ?string $reason = null): Task
    {
        $project = $task->project;
        if (! $project->acceptsWork()) {
            throw ValidationException::withMessages([
                'project' => 'Project is not active and does not accept work.',
            ]);
        }

        return DB::transaction(function () use ($task, $actor, $reason) {
            $lockedTask = Task::where('id', $task->id)->lockForUpdate()->firstOrFail();

            $isSubtask = $lockedTask->parent_task_id !== null;

            if (! $isSubtask && $lockedTask->status !== Task::STATUS_REVIEW) {
                throw ValidationException::withMessages([
                    'status' => "Task must be in review status to be approved (current: '{$lockedTask->status}').",
                ]);
            }

            if ($isSubtask && ! in_array($lockedTask->status, [Task::STATUS_IN_PROGRESS, Task::STATUS_REVIEW], true)) {
                throw ValidationException::withMessages([
                    'status' => "Subtask must be in progress or review to be completed (current: '{$lockedTask->status}').",
                ]);
            }

            // Self-approval guard: Current Assignee
            if ((int) $lockedTask->assigned_to_id === (int) $actor->id) {
                abort(403, 'Assignees cannot approve their own work.');
            }

            // Self-approval guard: Submitter (even if reassigned)
            if ($lockedTask->submitted_by_id !== null && (int) $lockedTask->submitted_by_id === (int) $actor->id) {
                abort(403, 'Task submitters cannot approve their own work.');
            }

            // Subtask self-approval check
            if ($lockedTask->parent_task_id !== null && (int) $lockedTask->assigned_to_id === (int) $actor->id) {
                abort(403, 'Subtask assignees cannot approve their own subtask.');
            }

            // Check subtasks
            $incompleteSubtasks = $lockedTask->subtasks()
                ->where('status', '!=', Task::STATUS_COMPLETED)
                ->count();

            if ($incompleteSubtasks > 0) {
                throw ValidationException::withMessages([
                    'subtasks' => "Cannot complete task: {$incompleteSubtasks} subtask(s) are still incomplete.",
                ]);
            }

            // Stop any running timers
            foreach ($lockedTask->timeEntries()->running()->get() as $runningEntry) {
                $runningEntry->stop();
            }

            $lockedTask->update([
                'status'       => Task::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            // Audit activity
            $lockedTask->project->recordActivity(
                action: 'task_completed',
                description: "Task '{$lockedTask->title}' was approved and completed by {$actor->name}.",
                userId: $actor->id,
                field: 'status',
                oldValue: Task::STATUS_REVIEW,
                newValue: Task::STATUS_COMPLETED,
                reason: $reason,
                taskId: $lockedTask->id,
            );

            // Update latest review round
            $latestReview = $lockedTask->reviews()->whereNull('outcome')->latest('round_number')->first();
            if ($latestReview) {
                $latestReview->update([
                    'reviewer_id' => $actor->id,
                    'decided_at'  => now(),
                    'outcome'     => 'approved',
                    'feedback'    => $reason,
                ]);
            } else {
                TaskReview::create([
                    'company_id'      => $lockedTask->company_id,
                    'task_id'         => $lockedTask->id,
                    'round_number'    => ((int) $lockedTask->reviews()->max('round_number')) + 1,
                    'submitted_by_id' => $lockedTask->submitted_by_id,
                    'submitted_at'    => $lockedTask->submitted_at,
                    'reviewer_id'     => $actor->id,
                    'decided_at'      => now(),
                    'outcome'         => 'approved',
                    'feedback'        => $reason,
                ]);
            }

            DB::afterCommit(function () use ($lockedTask, $actor, $reason) {
                TaskCompletedEvent::dispatch($lockedTask, $actor, $reason);
            });

            return $lockedTask;
        });
    }

    /**
     * Request changes on task under review.
     */
    public function requestChanges(Task $task, User $actor, string $reason): Task
    {
        $project = $task->project;
        if (! $project->acceptsWork()) {
            throw ValidationException::withMessages([
                'project' => 'Project is not active and does not accept work.',
            ]);
        }

        if (empty(trim($reason))) {
            throw ValidationException::withMessages([
                'reason' => 'A non-empty reason is required when requesting changes.',
            ]);
        }

        return DB::transaction(function () use ($task, $actor, $reason) {
            $lockedTask = Task::where('id', $task->id)->lockForUpdate()->firstOrFail();

            if ($lockedTask->status !== Task::STATUS_REVIEW) {
                throw ValidationException::withMessages([
                    'status' => "Task must be in review status to request changes (current: '{$lockedTask->status}').",
                ]);
            }

            $lockedTask->update([
                'status' => Task::STATUS_CHANGES_REQUIRED,
            ]);

            $lockedTask->project->recordActivity(
                action: 'task_changes_requested',
                description: "Changes were requested on task '{$lockedTask->title}' by {$actor->name}: {$reason}",
                userId: $actor->id,
                field: 'status',
                oldValue: Task::STATUS_REVIEW,
                newValue: Task::STATUS_CHANGES_REQUIRED,
                reason: $reason,
                taskId: $lockedTask->id,
            );

            $latestReview = $lockedTask->reviews()->whereNull('outcome')->latest('round_number')->first();
            if ($latestReview) {
                $latestReview->update([
                    'reviewer_id' => $actor->id,
                    'decided_at'  => now(),
                    'outcome'     => 'changes_requested',
                    'feedback'    => $reason,
                ]);
            } else {
                TaskReview::create([
                    'company_id'      => $lockedTask->company_id,
                    'task_id'         => $lockedTask->id,
                    'round_number'    => ((int) $lockedTask->reviews()->max('round_number')) + 1,
                    'submitted_by_id' => $lockedTask->submitted_by_id,
                    'submitted_at'    => $lockedTask->submitted_at,
                    'reviewer_id'     => $actor->id,
                    'decided_at'      => now(),
                    'outcome'         => 'changes_requested',
                    'feedback'        => $reason,
                ]);
            }

            DB::afterCommit(function () use ($lockedTask, $actor, $reason) {
                TaskChangesRequestedEvent::dispatch($lockedTask, $actor, $reason);
            });

            return $lockedTask;
        });
    }

    /**
     * Reopen a completed task.
     */
    public function reopen(Task $task, User $actor, string $reason): Task
    {
        $project = $task->project;
        if (! $project->acceptsWork()) {
            throw ValidationException::withMessages([
                'project' => 'Project is not active and does not accept work.',
            ]);
        }

        if (empty(trim($reason))) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required to reopen a completed task.',
            ]);
        }

        return DB::transaction(function () use ($task, $actor, $reason) {
            $lockedTask = Task::where('id', $task->id)->lockForUpdate()->firstOrFail();

            if ($lockedTask->status !== Task::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => "Only completed tasks can be reopened (current: '{$lockedTask->status}').",
                ]);
            }

            $lockedTask->update([
                'status'          => Task::STATUS_IN_PROGRESS,
                'completed_at'    => null,
                'submitted_by_id' => null,
                'submitted_at'    => null,
            ]);

            $lockedTask->project->recordActivity(
                action: 'task_reopened',
                description: "Task '{$lockedTask->title}' was reopened by {$actor->name}: {$reason}",
                userId: $actor->id,
                field: 'status',
                oldValue: Task::STATUS_COMPLETED,
                newValue: Task::STATUS_IN_PROGRESS,
                reason: $reason,
                taskId: $lockedTask->id,
            );

            DB::afterCommit(function () use ($lockedTask, $actor, $reason) {
                TaskReopenedEvent::dispatch($lockedTask, $actor, $reason);
            });

            return $lockedTask;
        });
    }

    /**
     * Reassign task to another user with stricter state transition rules.
     */
    public function reassign(Task $task, User $actor, ?int $newAssigneeId, ?string $reason = null): Task
    {
        $project = $task->project;
        if (! $project->acceptsWork()) {
            throw ValidationException::withMessages([
                'project' => 'Project is not active and does not accept work.',
            ]);
        }

        // 1. Completed tasks cannot be reassigned
        if ($task->status === Task::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => 'Completed tasks cannot be reassigned. Reopen the task first.',
            ]);
        }

        // 2. Tasks in progress, review, or changes_required require a reason
        $isStarted = in_array($task->status, [
            Task::STATUS_IN_PROGRESS,
            Task::STATUS_REVIEW,
            Task::STATUS_CHANGES_REQUIRED,
        ], true);

        if ($isStarted && empty(trim((string) $reason))) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when reassigning a task that has already started.',
            ]);
        }

        return DB::transaction(function () use ($task, $actor, $newAssigneeId, $reason, $isStarted) {
            $lockedTask = Task::where('id', $task->id)->lockForUpdate()->firstOrFail();

            $oldAssigneeId = $lockedTask->assigned_to_id;
            $oldAssignee = $lockedTask->assignedTo;
            $newAssignee = $newAssigneeId ? User::find($newAssigneeId) : null;

            // Stop any running timer belonging to the previous assignee
            if ($oldAssigneeId) {
                foreach ($lockedTask->timeEntries()->running()->where('user_id', $oldAssigneeId)->get() as $timer) {
                    $timer->stop();
                    $lockedTask->project->recordActivity(
                        action: 'timer_stopped',
                        description: "Running timer on task '{$lockedTask->title}' for previous assignee was automatically stopped upon reassignment.",
                        userId: $actor->id,
                        taskId: $lockedTask->id,
                    );
                }
            }

            // When reassigned from active work state, move back to assigned state
            $newStatus = $lockedTask->status;
            if (in_array($lockedTask->status, [Task::STATUS_IN_PROGRESS, Task::STATUS_REVIEW, Task::STATUS_CHANGES_REQUIRED], true)) {
                $newStatus = Task::STATUS_ASSIGNED;
            }

            $lockedTask->update([
                'assigned_to_id' => $newAssigneeId,
                'status'         => $newStatus,
            ]);

            $desc = $newAssignee
                ? "Task '{$lockedTask->title}' reassigned from " . ($oldAssignee?->name ?? 'Unassigned') . " to {$newAssignee->name} by {$actor->name}."
                : "Task '{$lockedTask->title}' was unassigned by {$actor->name}.";

            if ($reason) {
                $desc .= " Reason: {$reason}";
            }

            $lockedTask->project->recordActivity(
                action: 'task_reassigned',
                description: $desc,
                userId: $actor->id,
                field: 'assigned_to_id',
                oldValue: (string) $oldAssigneeId,
                newValue: (string) $newAssigneeId,
                reason: $reason,
                taskId: $lockedTask->id,
            );

            DB::afterCommit(function () use ($lockedTask, $newAssignee, $actor, $oldAssignee, $reason) {
                TaskAssignedEvent::dispatch($lockedTask, $newAssignee, $actor, $oldAssignee, $reason);
            });

            return $lockedTask;
        });
    }

    /**
     * Unified status transition method (used by /status endpoint).
     */
    public function transitionStatus(Task $task, User $actor, string $newStatus, ?string $reason = null): Task
    {
        $oldStatus = $task->status;
        if ($oldStatus === $newStatus) {
            return $task;
        }

        return match ($newStatus) {
            Task::STATUS_REVIEW           => $this->submitForReview($task, $actor, $reason),
            Task::STATUS_COMPLETED        => $this->approve($task, $actor, $reason),
            Task::STATUS_CHANGES_REQUIRED => $this->requestChanges($task, $actor, (string) $reason),
            Task::STATUS_IN_PROGRESS      => ($oldStatus === Task::STATUS_COMPLETED)
                ? $this->reopen($task, $actor, (string) $reason)
                : $this->startProgress($task, $actor, $reason),
            default                       => $this->basicStatusChange($task, $actor, $newStatus, $reason),
        };
    }

    private function startProgress(Task $task, User $actor, ?string $reason = null): Task
    {
        $project = $task->project;
        if (! $project->acceptsWork()) {
            throw ValidationException::withMessages([
                'project' => 'Project is not active and does not accept work.',
            ]);
        }

        return DB::transaction(function () use ($task, $actor, $reason) {
            $lockedTask = Task::where('id', $task->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedTask->status;

            $updates = ['status' => Task::STATUS_IN_PROGRESS];
            if ($lockedTask->started_at === null) {
                $updates['started_at'] = now();
            }

            $lockedTask->update($updates);

            $lockedTask->project->recordActivity(
                action: 'task_status_changed',
                description: "Task '{$lockedTask->title}' moved to in_progress by {$actor->name}." . ($reason ? " Reason: {$reason}" : ''),
                userId: $actor->id,
                field: 'status',
                oldValue: $oldStatus,
                newValue: Task::STATUS_IN_PROGRESS,
                reason: $reason,
                taskId: $lockedTask->id,
            );

            return $lockedTask;
        });
    }

    private function basicStatusChange(Task $task, User $actor, string $newStatus, ?string $reason = null): Task
    {
        $project = $task->project;
        if (! $project->acceptsWork()) {
            throw ValidationException::withMessages([
                'project' => 'Project is not active and does not accept work.',
            ]);
        }

        return DB::transaction(function () use ($task, $actor, $newStatus, $reason) {
            $lockedTask = Task::where('id', $task->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedTask->status;

            $lockedTask->update(['status' => $newStatus]);

            $lockedTask->project->recordActivity(
                action: 'task_status_changed',
                description: "Task '{$lockedTask->title}' status changed from '{$oldStatus}' to '{$newStatus}' by {$actor->name}.",
                userId: $actor->id,
                field: 'status',
                oldValue: $oldStatus,
                newValue: $newStatus,
                reason: $reason,
                taskId: $lockedTask->id,
            );

            return $lockedTask;
        });
    }
}
