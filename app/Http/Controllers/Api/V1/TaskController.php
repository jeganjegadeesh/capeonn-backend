<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TaskAssignRequest;
use App\Http\Requests\Tasks\TaskRequest;
use App\Http\Requests\Tasks\TaskStatusRequest;
use App\Http\Resources\TaskDetailResource;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    private const WITH = [
        'assignedTo:id,name,email,employee_code,avatar_url',
        'createdBy:id,name',
        'timeEntries',
    ];

    public function __construct(private AccessControl $access)
    {
    }

    /**
     * GET /projects/{project}/tasks
     * List tasks for a given project with filters.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessProject($actor, $project)) {
            return response()->json(['message' => 'Unauthorized project access.'], 403);
        }

        $query = $project->tasks()
            ->with(self::WITH)
            ->withCount('subtasks');

        // Status filter (single or comma-separated)
        if ($request->filled('status')) {
            $statuses = array_filter(explode(',', (string) $request->query('status')));
            $query->whereIn('status', $statuses);
        }

        // Priority filter
        if ($request->filled('priority')) {
            $query->where('priority', $request->query('priority'));
        }

        // Assigned To filter
        if ($request->filled('assigned_to_id')) {
            $val = $request->query('assigned_to_id');
            if ($val === 'unassigned') {
                $query->whereNull('assigned_to_id');
            } else {
                $query->where('assigned_to_id', (int) $val);
            }
        }

        // Root tasks only vs subtasks
        if ($request->boolean('root_only', false)) {
            $query->whereNull('parent_task_id');
        } elseif ($request->filled('parent_task_id')) {
            $query->where('parent_task_id', (int) $request->query('parent_task_id'));
        }

        // Search in title or description
        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->query('sort_by', 'created_at');
        $sortDir = strtolower($request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['created_at', 'due_date', 'priority', 'status', 'title', 'actual_hours'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderByDesc('id');
        }

        $tasks = $query->get();

        return response()->json([
            'data' => TaskResource::collection($tasks),
        ]);
    }

    /**
     * POST /projects/{project}/tasks
     * Create a new task within a project.
     */
    public function store(TaskRequest $request, Project $project): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canManageTaskInProject($actor, $project)) {
            return response()->json([
                'message' => 'You do not have permission to create tasks in this project.',
            ], 403);
        }

        if (! $project->acceptsWork()) {
            return response()->json([
                'message' => "Project status [{$project->status}] does not accept new tasks or modifications.",
            ], 422);
        }

        $data = $request->validated();
        $assignedToId = $data['assigned_to_id'] ?? null;
        $status = $assignedToId ? Task::STATUS_ASSIGNED : Task::STATUS_BACKLOG;

        $task = DB::transaction(function () use ($project, $actor, $data, $assignedToId, $status) {
            $task = Task::create([
                'company_id'      => $project->company_id,
                'project_id'      => $project->id,
                'parent_task_id'  => $data['parent_task_id'] ?? null,
                'title'           => $data['title'],
                'description'     => $data['description'] ?? null,
                'status'          => $status,
                'priority'        => $data['priority'] ?? Task::PRIORITY_MEDIUM,
                'assigned_to_id'  => $assignedToId,
                'created_by_id'   => $actor->id,
                'due_date'        => $data['due_date'] ?? null,
                'estimated_hours' => $data['estimated_hours'] ?? null,
                'actual_hours'    => 0,
            ]);

            // Append-only audit log
            $project->recordActivity(
                action: 'task_created',
                description: "Task '{$task->title}' was created by {$actor->name}.",
                userId: $actor->id,
                taskId: $task->id,
            );

            if ($assignedToId) {
                $assignedUser = User::find($assignedToId);
                $project->recordActivity(
                    action: 'task_assigned',
                    description: "Task '{$task->title}' assigned to {$assignedUser?->name}.",
                    userId: $actor->id,
                    field: 'assigned_to_id',
                    newValue: (string) $assignedToId,
                    taskId: $task->id,
                );
            }

            return $task;
        });

        $task->load(self::WITH);
        $task->loadCount('subtasks');

        return response()->json([
            'message' => 'Task created successfully.',
            'data'    => TaskResource::make($task),
        ], 201);
    }

    /**
     * GET /tasks/{task}
     * Show task details with subtasks, time entries and activities.
     */
    public function show(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        $task->load([
            'project:id,name,code,status,manager_id,team_lead_id',
            'assignedTo:id,name,email,employee_code,avatar_url',
            'createdBy:id,name',
            'parentTask:id,title,status',
            'subtasks' => fn ($q) => $q->with(['assignedTo:id,name,email,avatar_url'])->withCount('subtasks'),
            'timeEntries' => fn ($q) => $q->with(['user:id,name,email,avatar_url'])->latest('id')->limit(50),
            'activities' => fn ($q) => $q->with(['user:id,name'])->latest('id')->limit(50),
        ]);
        $task->loadCount('subtasks');

        return response()->json([
            'data' => TaskDetailResource::make($task),
        ]);
    }

    /**
     * PUT /tasks/{task}
     * Update task details.
     */
    public function update(TaskRequest $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canManageTask($actor, $task)) {
            return response()->json(['message' => 'You do not have permission to manage this task.'], 403);
        }

        if (! $task->project->acceptsWork()) {
            return response()->json([
                'message' => "Project status [{$task->project->status}] does not accept task modifications.",
            ], 422);
        }

        $data = $request->validated();
        $oldAssigneeId = $task->assigned_to_id;
        $newAssigneeId = array_key_exists('assigned_to_id', $data) ? $data['assigned_to_id'] : $oldAssigneeId;

        DB::transaction(function () use ($task, $actor, $data, $oldAssigneeId, $newAssigneeId) {
            $changes = [];
            foreach (['title', 'description', 'priority', 'due_date', 'estimated_hours'] as $field) {
                if (array_key_exists($field, $data) && (string) $task->{$field} !== (string) $data[$field]) {
                    $changes[$field] = [
                        'old' => (string) $task->{$field},
                        'new' => (string) $data[$field],
                    ];
                }
            }

            $task->update($data);

            // Log significant edits
            foreach ($changes as $field => $val) {
                $task->project->recordActivity(
                    action: 'task_updated',
                    description: "Task '{$task->title}' {$field} updated from '{$val['old']}' to '{$val['new']}' by {$actor->name}.",
                    userId: $actor->id,
                    field: $field,
                    oldValue: $val['old'],
                    newValue: $val['new'],
                    taskId: $task->id,
                );
            }

            // Handle assignment change if present
            if (array_key_exists('assigned_to_id', $data) && (int) $oldAssigneeId !== (int) $newAssigneeId) {
                $newAssignee = $newAssigneeId ? User::find($newAssigneeId) : null;
                $desc = $newAssignee
                    ? "Task '{$task->title}' assigned to {$newAssignee->name}."
                    : "Task '{$task->title}' unassigned.";

                // If task was backlog and now has assignee, move to assigned
                if ($newAssigneeId && $task->status === Task::STATUS_BACKLOG) {
                    $task->update(['status' => Task::STATUS_ASSIGNED]);
                } elseif (! $newAssigneeId && $task->status === Task::STATUS_ASSIGNED) {
                    $task->update(['status' => Task::STATUS_BACKLOG]);
                }

                $task->project->recordActivity(
                    action: 'task_assigned',
                    description: $desc,
                    userId: $actor->id,
                    field: 'assigned_to_id',
                    oldValue: (string) $oldAssigneeId,
                    newValue: (string) $newAssigneeId,
                    taskId: $task->id,
                );
            }
        });

        $task->refresh()->load(self::WITH)->loadCount('subtasks');

        return response()->json([
            'message' => 'Task updated successfully.',
            'data'    => TaskResource::make($task),
        ]);
    }

    /**
     * DELETE /tasks/{task}
     * Soft delete a task and its subtasks.
     */
    public function destroy(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canManageTask($actor, $task)) {
            return response()->json(['message' => 'You do not have permission to delete this task.'], 403);
        }

        if (! $task->project->acceptsWork()) {
            return response()->json([
                'message' => "Project status [{$task->project->status}] does not accept modifications.",
            ], 422);
        }

        if ($task->timeEntries()->running()->exists()) {
            return response()->json([
                'message' => 'Cannot delete task with an active running timer. Stop the timer first.',
            ], 422);
        }

        DB::transaction(function () use ($task, $actor) {
            $task->project->recordActivity(
                action: 'task_deleted',
                description: "Task '{$task->title}' was deleted by {$actor->name}.",
                userId: $actor->id,
                taskId: $task->id,
            );

            // Soft-delete subtasks
            $task->subtasks()->delete();
            $task->delete();
        });

        return response()->json([
            'message' => 'Task deleted successfully.',
        ]);
    }

    /**
     * POST /tasks/{task}/status
     * Lifecycle status transitions.
     */
    public function updateStatus(TaskStatusRequest $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canUpdateTaskStatus($actor, $task)) {
            return response()->json([
                'message' => 'You do not have permission to update status for this task.',
            ], 403);
        }

        if (! $task->project->acceptsWork()) {
            return response()->json([
                'message' => "Project status [{$task->project->status}] does not accept task updates.",
            ], 422);
        }

        $oldStatus = $task->status;
        $newStatus = $request->input('status');
        $reason = $request->input('reason');

        if ($oldStatus === $newStatus) {
            return response()->json([
                'message' => 'Task is already in this status.',
                'data'    => TaskResource::make($task->load(self::WITH)->loadCount('subtasks')),
            ]);
        }

        $allowed = Task::ALLOWED_TRANSITIONS[$oldStatus] ?? [];
        if (! in_array($newStatus, $allowed, true)) {
            return response()->json([
                'message' => "Status transition from '{$oldStatus}' to '{$newStatus}' is not permitted.",
                'allowed_transitions' => $allowed,
            ], 422);
        }

        // Subtask completion validation
        if ($newStatus === Task::STATUS_COMPLETED) {
            $incompleteCount = $task->subtasks()->where('status', '!=', Task::STATUS_COMPLETED)->count();
            if ($incompleteCount > 0) {
                return response()->json([
                    'message' => "Cannot complete task: {$incompleteCount} subtask(s) are still incomplete.",
                ], 422);
            }
        }

        DB::transaction(function () use ($task, $actor, $oldStatus, $newStatus, $reason) {
            $updates = ['status' => $newStatus];

            if ($newStatus === Task::STATUS_IN_PROGRESS && $task->started_at === null) {
                $updates['started_at'] = now();
            }

            if ($newStatus === Task::STATUS_COMPLETED) {
                $updates['completed_at'] = now();

                // Stop any running timers on this task
                foreach ($task->timeEntries()->running()->get() as $runningEntry) {
                    $runningEntry->stop();
                }
            } elseif ($oldStatus === Task::STATUS_COMPLETED && $newStatus === Task::STATUS_IN_PROGRESS) {
                // Re-opening task
                $updates['completed_at'] = null;
            }

            $task->update($updates);

            // Audit activity log
            $task->project->recordActivity(
                action: 'task_status_changed',
                description: "Task '{$task->title}' status changed from '{$oldStatus}' to '{$newStatus}' by {$actor->name}.",
                userId: $actor->id,
                field: 'status',
                oldValue: $oldStatus,
                newValue: $newStatus,
                reason: $reason,
                taskId: $task->id,
            );
        });

        $task->refresh()->load(self::WITH)->loadCount('subtasks');

        return response()->json([
            'message' => 'Task status updated successfully.',
            'data'    => TaskResource::make($task),
        ]);
    }

    /**
     * POST /tasks/{task}/assign
     * Explicit assignment endpoint.
     */
    public function assign(TaskAssignRequest $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canManageTask($actor, $task)) {
            return response()->json([
                'message' => 'You do not have permission to assign this task.',
            ], 403);
        }

        if (! $task->project->acceptsWork()) {
            return response()->json([
                'message' => "Project status [{$task->project->status}] does not accept task modifications.",
            ], 422);
        }

        $oldAssigneeId = $task->assigned_to_id;
        $newAssigneeId = $request->input('assigned_to_id');

        if ((int) $oldAssigneeId === (int) $newAssigneeId) {
            return response()->json([
                'message' => 'Task already has this assignment.',
                'data'    => TaskResource::make($task->load(self::WITH)->loadCount('subtasks')),
            ]);
        }

        DB::transaction(function () use ($task, $actor, $oldAssigneeId, $newAssigneeId) {
            $updates = ['assigned_to_id' => $newAssigneeId];

            if ($newAssigneeId && $task->status === Task::STATUS_BACKLOG) {
                $updates['status'] = Task::STATUS_ASSIGNED;
            } elseif (! $newAssigneeId && $task->status === Task::STATUS_ASSIGNED) {
                $updates['status'] = Task::STATUS_BACKLOG;
            }

            $task->update($updates);

            $newAssignee = $newAssigneeId ? User::find($newAssigneeId) : null;
            $desc = $newAssignee
                ? "Task '{$task->title}' assigned to {$newAssignee->name}."
                : "Task '{$task->title}' was unassigned.";

            $task->project->recordActivity(
                action: 'task_assigned',
                description: $desc,
                userId: $actor->id,
                field: 'assigned_to_id',
                oldValue: (string) $oldAssigneeId,
                newValue: (string) $newAssigneeId,
                taskId: $task->id,
            );
        });

        $task->refresh()->load(self::WITH)->loadCount('subtasks');

        return response()->json([
            'message' => 'Task assignment updated successfully.',
            'data'    => TaskResource::make($task),
        ]);
    }

    /**
     * GET /tasks/my
     * Tasks assigned to current authenticated user.
     */
    public function myTasks(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = $this->access->constrainTasks(Task::query(), $actor, 'tasks.view')
            ->where('tasks.assigned_to_id', $actor->id)
            ->with(self::WITH)
            ->withCount('subtasks');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->query('priority'));
        }

        if ($request->boolean('is_overdue')) {
            $query->where('due_date', '<', now()->toDateString())
                ->where('status', '!=', Task::STATUS_COMPLETED);
        }

        $tasks = $query->orderBy('due_date', 'asc')->orderByDesc('id')->get();

        return response()->json([
            'data' => TaskResource::collection($tasks),
        ]);
    }

    /**
     * GET /tasks/{task}/subtasks
     * List subtasks for a given task.
     */
    public function subtasks(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        $subtasks = $task->subtasks()
            ->with(self::WITH)
            ->withCount('subtasks')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'data' => TaskResource::collection($subtasks),
        ]);
    }

    /**
     * POST /tasks/{task}/subtasks
     * Create a subtask directly on a parent task.
     */
    public function createSubtask(TaskRequest $request, Task $task): JsonResponse
    {
        $request->merge(['parent_task_id' => $task->id]);
        return $this->store($request, $task->project);
    }
}
