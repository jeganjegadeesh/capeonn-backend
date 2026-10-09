<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\Tasks\TaskAssignedEvent;
use App\Events\Tasks\TaskChangesRequestedEvent;
use App\Events\Tasks\TaskCompletedEvent;
use App\Events\Tasks\TaskReopenedEvent;
use App\Events\Tasks\TaskSubmittedForReviewEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TaskAssignRequest;
use App\Http\Requests\Tasks\TaskRequest;
use App\Http\Requests\Tasks\TaskStatusRequest;
use App\Http\Resources\ProjectActivityResource;
use App\Http\Resources\TaskDetailResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\TimeEntryResource;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AccessControl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    private const WITH = [
        'assignedTo:id,name,email,employee_code',
        'createdBy:id,name',
        'submittedBy:id,name,email',
        'reviewer:id,name',
        'latestReview',
        'timeEntries',
    ];

    public function __construct(
        private AccessControl $access,
        private \App\Services\TaskWorkflowService $workflow,
    ) {
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

        // Regular employees (without manage permission and not team lead) see only their assigned tasks
        if (! $this->access->canManageTaskInProject($actor, $project) && (int) $project->team_lead_id !== (int) $actor->id) {
            $query->where('assigned_to_id', $actor->id);
        }

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

        // Sorting (includes position)
        $sortBy = $request->query('sort_by', 'position');
        $sortDir = strtolower($request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (in_array($sortBy, ['created_at', 'due_date', 'priority', 'status', 'title', 'actual_hours', 'position'], true)) {
            $query->orderBy($sortBy, $sortDir)->orderByDesc('id');
        } else {
            $query->orderBy('position', 'asc')->orderByDesc('id');
        }

        // Pagination if requested
        if ($request->has('per_page') || $request->has('page')) {
            $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
            $paginator = $query->paginate($perPage);

            return response()->json([
                'data'  => TaskResource::collection($paginator->items()),
                'links' => [
                    'first' => $paginator->url(1),
                    'last'  => $paginator->url($paginator->lastPage()),
                    'prev'  => $paginator->previousPageUrl(),
                    'next'  => $paginator->nextPageUrl(),
                ],
                'meta'  => [
                    'current_page' => $paginator->currentPage(),
                    'from'         => $paginator->firstItem(),
                    'last_page'    => $paginator->lastPage(),
                    'path'         => $paginator->path(),
                    'per_page'     => $paginator->perPage(),
                    'to'           => $paginator->lastItem(),
                    'total'        => $paginator->total(),
                ],
            ]);
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

        // Due date warning check
        $warning = null;
        if (! empty($data['due_date']) && ! empty($project->deadline)) {
            if (Carbon::parse($data['due_date'])->gt(Carbon::parse($project->deadline))) {
                $warning = "Task due date ({$data['due_date']}) is after project deadline ({$project->deadline->toDateString()}).";
            }
        }

        $task = DB::transaction(function () use ($project, $actor, $data, $assignedToId, $status) {
            $task = Task::create([
                'company_id'      => $project->company_id,
                'project_id'      => $project->id,
                'parent_task_id'  => $data['parent_task_id'] ?? null,
                'title'           => $data['title'],
                'description'     => $data['description'] ?? null,
                'status'          => $status,
                'priority'        => $data['priority'] ?? Task::PRIORITY_MEDIUM,
                'position'        => $data['position'] ?? 0,
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

                TaskAssignedEvent::dispatch($task, $assignedUser, $actor);
            }

            return $task;
        });

        $task->load(self::WITH);
        $task->loadCount('subtasks');

        $response = [
            'message' => 'Task created successfully.',
            'data'    => TaskResource::make($task),
        ];

        if ($warning) {
            $response['warning'] = $warning;
        }

        return response()->json($response, 201);
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

        // Time detail visibility: assignees, TL, Manager, Admin see all. Regular members see own entries.
        $canSeeAllTime = $this->access->canManageTask($actor, $task)
            || (int) $task->assigned_to_id === (int) $actor->id;

        // Activity log visibility follows project activity permissions
        $canSeeActivities = $this->access->canViewProjectActivity($actor, $task->project);

        $task->load([
            'project:id,name,code,status,manager_id,team_lead_id',
            'assignedTo:id,name,email,employee_code',
            'createdBy:id,name',
            'parentTask:id,title,status',
            'subtasks' => fn ($q) => $q->with(['assignedTo:id,name,email'])->withCount('subtasks'),
            'timeEntries' => fn ($q) => $canSeeAllTime
                ? $q->with(['user:id,name,email'])->latest('id')->limit(50)
                : $q->where('user_id', $actor->id)->with(['user:id,name,email'])->latest('id')->limit(50),
            'activities' => fn ($q) => $canSeeActivities
                ? $q->with(['user:id,name'])->latest('id')->limit(50)
                : $q->whereRaw('1 = 0'),
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

        // parent_task_id, project_id, company_id are immutable after creation
        unset($data['parent_task_id'], $data['project_id'], $data['company_id']);

        $oldAssigneeId = $task->assigned_to_id;
        $newAssigneeId = array_key_exists('assigned_to_id', $data) ? $data['assigned_to_id'] : $oldAssigneeId;

        // Due date warning check
        $warning = null;
        if (! empty($data['due_date']) && ! empty($task->project->deadline)) {
            if (Carbon::parse($data['due_date'])->gt(Carbon::parse($task->project->deadline))) {
                $warning = "Task due date ({$data['due_date']}) is after project deadline ({$task->project->deadline->toDateString()}).";
            }
        }

        DB::transaction(function () use ($task, $actor, $data, $oldAssigneeId, $newAssigneeId, $request) {
            $changes = [];
            foreach (['title', 'description', 'priority', 'due_date', 'estimated_hours', 'position'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                if ($field === 'due_date') {
                    // Compare formatted date strings (Y-m-d) to prevent false change log entries
                    $oldVal = $task->due_date ? Carbon::parse($task->due_date)->format('Y-m-d') : null;
                    $newVal = ! empty($data['due_date']) ? Carbon::parse($data['due_date'])->format('Y-m-d') : null;
                    if ($oldVal !== $newVal) {
                        $changes[$field] = ['old' => (string) $oldVal, 'new' => (string) $newVal];
                    }
                } else {
                    if ((string) $task->{$field} !== (string) $data[$field]) {
                        $changes[$field] = [
                            'old' => (string) $task->{$field},
                            'new' => (string) $data[$field],
                        ];
                    }
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
                $oldAssignee = $oldAssigneeId ? User::find($oldAssigneeId) : null;

                // Stop any running timer for the previous assignee
                if ($oldAssigneeId) {
                    $runningEntries = $task->timeEntries()->running()->where('user_id', $oldAssigneeId)->get();
                    foreach ($runningEntries as $re) {
                        $re->stop();
                        $task->project->recordActivity(
                            action: 'timer_stopped',
                            description: "Running timer on task '{$task->title}' for previous assignee was automatically stopped upon reassignment.",
                            userId: $actor->id,
                            taskId: $task->id,
                        );
                    }
                }

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
                    reason: $request->input('reason'),
                    taskId: $task->id,
                );

                TaskAssignedEvent::dispatch($task, $newAssignee, $actor, $oldAssignee, $request->input('reason'));
            }
        });

        $task->refresh()->load(self::WITH)->loadCount('subtasks');

        $response = [
            'message' => 'Task updated successfully.',
            'data'    => TaskResource::make($task),
        ];

        if ($warning) {
            $response['warning'] = $warning;
        }

        return response()->json($response);
    }

    /**
     * DELETE /tasks/{task}
     * Soft delete a task and its subtasks.
     * Blocked if task or subtasks have any logged time entries or active running timers.
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

        // Subtask IDs list
        $subtaskIds = $task->subtasks()->pluck('id')->all();
        $allTaskIds = array_merge([$task->id], $subtaskIds);

        // Block deletion if any active timers are running on the task or subtasks
        $hasRunning = TimeEntry::whereIn('task_id', $allTaskIds)->running()->exists();
        if ($hasRunning) {
            return response()->json([
                'message' => 'Cannot delete task with an active running timer. Stop the timer first.',
            ], 422);
        }

        // Block deletion if any time entries exist on the task or subtasks
        $hasTimeEntries = TimeEntry::whereIn('task_id', $allTaskIds)->exists();
        if ($hasTimeEntries) {
            return response()->json([
                'message' => 'Cannot delete a task that has logged time entries. Archive or cancel the task instead.',
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

        $isSubtask = $task->parent_task_id !== null;
        $allowed = $isSubtask
            ? [
                Task::STATUS_BACKLOG          => [Task::STATUS_ASSIGNED, Task::STATUS_IN_PROGRESS, Task::STATUS_COMPLETED],
                Task::STATUS_ASSIGNED         => [Task::STATUS_IN_PROGRESS, Task::STATUS_COMPLETED, Task::STATUS_BACKLOG],
                Task::STATUS_IN_PROGRESS      => [Task::STATUS_COMPLETED, Task::STATUS_REVIEW, Task::STATUS_ASSIGNED],
                Task::STATUS_REVIEW           => [Task::STATUS_CHANGES_REQUIRED, Task::STATUS_COMPLETED],
                Task::STATUS_CHANGES_REQUIRED => [Task::STATUS_IN_PROGRESS],
                Task::STATUS_COMPLETED        => [Task::STATUS_IN_PROGRESS, Task::STATUS_BACKLOG],
            ][$oldStatus] ?? []
            : (Task::ALLOWED_TRANSITIONS[$oldStatus] ?? []);

        if (! in_array($newStatus, $allowed, true)) {
            return response()->json([
                'message' => "Status transition from '{$oldStatus}' to '{$newStatus}' is not permitted.",
                'allowed_transitions' => $allowed,
            ], 422);
        }

        if ($newStatus === Task::STATUS_COMPLETED) {
            if ((int) $task->assigned_to_id === (int) $actor->id) {
                return response()->json([
                    'message' => 'Assignees cannot approve their own work.',
                ], 403);
            }

            if ($task->submitted_by_id !== null && (int) $task->submitted_by_id === (int) $actor->id) {
                return response()->json([
                    'message' => 'Task submitters cannot approve their own work.',
                ], 403);
            }
        }

        if (in_array($newStatus, [Task::STATUS_COMPLETED, Task::STATUS_CHANGES_REQUIRED], true)) {
            if (! $this->access->canManageTask($actor, $task)) {
                return response()->json([
                    'message' => 'Only a Team Lead, Manager, or Admin can review or approve tasks.',
                ], 403);
            }
        }

        if ($oldStatus === Task::STATUS_COMPLETED && $newStatus === Task::STATUS_IN_PROGRESS) {
            if (! $this->access->canManageTask($actor, $task)) {
                return response()->json([
                    'message' => 'Only a Team Lead, Manager, or Admin can reopen a completed task.',
                ], 403);
            }
        }

        try {
            $task = $this->workflow->transitionStatus($task, $actor, $newStatus, $reason);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $firstMessage = collect($errors)->flatten()->first() ?? $e->getMessage();

            return response()->json([
                'success' => false,
                'message' => $firstMessage,
                'errors'  => $errors,
            ], 422);
        }

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

        $newAssigneeId = $request->input('assigned_to_id');
        $reason = $request->input('reason');

        $task = $this->workflow->reassign($task, $actor, $newAssigneeId ? (int) $newAssigneeId : null, $reason);

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

        $query->orderBy('due_date', 'asc')->orderByDesc('id');

        if ($request->has('per_page') || $request->has('page')) {
            $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
            $paginator = $query->paginate($perPage);

            return response()->json([
                'data'  => TaskResource::collection($paginator->items()),
                'links' => [
                    'first' => $paginator->url(1),
                    'last'  => $paginator->url($paginator->lastPage()),
                    'prev'  => $paginator->previousPageUrl(),
                    'next'  => $paginator->nextPageUrl(),
                ],
                'meta'  => [
                    'current_page' => $paginator->currentPage(),
                    'from'         => $paginator->firstItem(),
                    'last_page'    => $paginator->lastPage(),
                    'path'         => $paginator->path(),
                    'per_page'     => $paginator->perPage(),
                    'to'           => $paginator->lastItem(),
                    'total'        => $paginator->total(),
                ],
            ]);
        }

        $tasks = $query->get();

        return response()->json([
            'data' => TaskResource::collection($tasks),
        ]);
    }

    /**
     * GET /tasks/my-work-today
     * Work summary today: tasks due today, overdue tasks, in progress tasks, and hours logged today.
     */
    public function myWorkToday(Request $request): JsonResponse
    {
        $actor = $request->user();
        $today = Carbon::today()->toDateString();

        $dueToday = Task::where('assigned_to_id', $actor->id)
            ->whereDate('due_date', $today)
            ->where('status', '!=', Task::STATUS_COMPLETED)
            ->with(self::WITH)
            ->get();

        $overdue = Task::where('assigned_to_id', $actor->id)
            ->whereDate('due_date', '<', $today)
            ->where('status', '!=', Task::STATUS_COMPLETED)
            ->with(self::WITH)
            ->get();

        $inProgress = Task::where('assigned_to_id', $actor->id)
            ->where('status', Task::STATUS_IN_PROGRESS)
            ->with(self::WITH)
            ->get();

        // Hours logged today: completed entries today + active timer elapsed today
        $completedSecondsToday = (int) TimeEntry::where('user_id', $actor->id)
            ->whereDate('started_at', $today)
            ->completed()
            ->sum('duration_seconds');

        $activeTimer = TimeEntry::with(self::WITH)
            ->where('user_id', $actor->id)
            ->running()
            ->first();

        $activeSeconds = 0;
        if ($activeTimer) {
            $effectiveEnd = $activeTimer->is_paused ? $activeTimer->paused_at : now();
            $activeSeconds = max(0, (int) $activeTimer->started_at->diffInSeconds($effectiveEnd));
        }

        $totalHoursToday = round(($completedSecondsToday + $activeSeconds) / 3600, 2);

        return response()->json([
            'data' => [
                'due_today_tasks'    => TaskResource::collection($dueToday),
                'overdue_tasks'      => TaskResource::collection($overdue),
                'in_progress_tasks'  => TaskResource::collection($inProgress),
                'hours_logged_today' => $totalHoursToday,
                'active_timer'       => $activeTimer ? TimeEntryResource::make($activeTimer) : null,
            ],
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
            ->orderBy('position', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'data' => TaskResource::collection($subtasks),
        ]);
    }

    /**
     * POST /tasks/{task}/subtasks
     * Create a subtask directly on a parent task.
     * Enforces one-level nesting and explicitly sets parent_task_id.
     */
    public function createSubtask(TaskRequest $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canManageTask($actor, $task)) {
            return response()->json([
                'message' => 'You do not have permission to create subtasks in this project.',
            ], 403);
        }

        if (! $task->project->acceptsWork()) {
            return response()->json([
                'message' => "Project status [{$task->project->status}] does not accept new tasks or modifications.",
            ], 422);
        }

        // Subtask cannot be created on another subtask (only 1-level nesting)
        if ($task->parent_task_id !== null) {
            return response()->json([
                'message' => 'Nested subtasks beyond one level are not supported.',
            ], 422);
        }

        $data = $request->validated();
        $assignedToId = $data['assigned_to_id'] ?? null;
        $status = $assignedToId ? Task::STATUS_ASSIGNED : Task::STATUS_BACKLOG;

        $subtask = DB::transaction(function () use ($task, $actor, $data, $assignedToId, $status) {
            $created = Task::create([
                'company_id'      => $task->company_id,
                'project_id'      => $task->project_id,
                'parent_task_id'  => $task->id,
                'title'           => $data['title'],
                'description'     => $data['description'] ?? null,
                'status'          => $status,
                'priority'        => $data['priority'] ?? Task::PRIORITY_MEDIUM,
                'position'        => $data['position'] ?? 0,
                'assigned_to_id'  => $assignedToId,
                'created_by_id'   => $actor->id,
                'due_date'        => $data['due_date'] ?? null,
                'estimated_hours' => $data['estimated_hours'] ?? null,
                'actual_hours'    => 0,
            ]);

            $task->project->recordActivity(
                action: 'task_created',
                description: "Subtask '{$created->title}' was created under task '{$task->title}' by {$actor->name}.",
                userId: $actor->id,
                taskId: $created->id,
            );

            if ($assignedToId) {
                $assignedUser = User::find($assignedToId);
                $task->project->recordActivity(
                    action: 'task_assigned',
                    description: "Subtask '{$created->title}' assigned to {$assignedUser?->name}.",
                    userId: $actor->id,
                    field: 'assigned_to_id',
                    newValue: (string) $assignedToId,
                    taskId: $created->id,
                );

                TaskAssignedEvent::dispatch($created, $assignedUser, $actor);
            }

            return $created;
        });

        $subtask->load(self::WITH);
        $subtask->loadCount('subtasks');

        return response()->json([
            'message' => 'Subtask created successfully.',
            'data'    => TaskResource::make($subtask),
        ], 201);
    }

    /**
     * POST /tasks/{task}/submit-for-review
     * Assignee submits task for review.
     */
    public function submitForReview(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        if (! $this->access->canUpdateTaskStatus($actor, $task)) {
            return response()->json(['message' => 'You do not have permission to submit this task for review.'], 403);
        }

        $notes = $request->input('notes') ?: $request->input('reason');
        $task = $this->workflow->submitForReview($task, $actor, $notes);

        $task->refresh()->load(self::WITH)->loadCount('subtasks');

        return response()->json([
            'message' => 'Task submitted for review successfully.',
            'data'    => TaskResource::make($task),
        ]);
    }

    /**
     * POST /tasks/{task}/approve
     * Team Lead, Manager, or Admin approves and completes a reviewed task.
     * Enforces self-approval guard: Assignees and submitters CANNOT approve their own work!
     */
    public function approve(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        if (! $this->access->canManageTask($actor, $task)) {
            return response()->json([
                'message' => 'Only a Team Lead, Manager, or Admin can approve tasks.',
            ], 403);
        }

        $reason = $request->input('reason') ?: $request->input('notes');
        $task = $this->workflow->approve($task, $actor, $reason);

        $task->refresh()->load(self::WITH)->loadCount('subtasks');

        return response()->json([
            'message' => 'Task approved and completed successfully.',
            'data'    => TaskResource::make($task),
        ]);
    }

    /**
     * POST /tasks/{task}/request-changes
     * Team Lead, Manager, or Admin requests changes on a task.
     * Mandatory feedback/reason required.
     */
    public function requestChanges(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        if (! $this->access->canManageTask($actor, $task)) {
            return response()->json([
                'message' => 'Only a Team Lead, Manager, or Admin can request changes.',
            ], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $task = $this->workflow->requestChanges($task, $actor, $validated['reason']);

        $task->refresh()->load(self::WITH)->loadCount('subtasks');

        return response()->json([
            'message' => 'Changes requested successfully.',
            'data'    => TaskResource::make($task),
        ]);
    }

    /**
     * POST /tasks/{task}/reopen
     * Team Lead, Manager, or Admin reopens a completed task.
     * Mandatory reason required.
     */
    public function reopen(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        if (! $this->access->canManageTask($actor, $task)) {
            return response()->json([
                'message' => 'Only a Team Lead, Manager, or Admin can reopen completed tasks.',
            ], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $task = $this->workflow->reopen($task, $actor, $validated['reason']);

        $task->refresh()->load(self::WITH)->loadCount('subtasks');

        return response()->json([
            'message' => 'Task reopened successfully.',
            'data'    => TaskResource::make($task),
        ]);
    }

    /**
     * POST /tasks/{task}/reassign
     * Reassign task with reason and stop active timers for previous assignee.
     */
    public function reassign(TaskAssignRequest $request, Task $task): JsonResponse
    {
        return $this->assign($request, $task);
    }

    /**
     * GET /tasks/review-queue
     * List tasks waiting for review for Team Leads and Managers.
     */
    public function reviewQueue(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = Task::where('tasks.company_id', $actor->company_id)
            ->where('tasks.status', Task::STATUS_REVIEW)
            ->whereHas('project', function ($p) {
                $p->whereNotIn('projects.status', [
                    Project::STATUS_CANCELLED,
                    Project::STATUS_ARCHIVED,
                    Project::STATUS_COMPLETED,
                    Project::STATUS_ON_HOLD,
                ]);
            })
            ->with([
                'project:id,name,code,department_id,team_lead_id,manager_id',
                'assignedTo:id,name,email,employee_code',
                'createdBy:id,name',
                'submittedBy:id,name,email',
                'latestReview',
            ])
            ->withCount('subtasks');

        if (! $actor->hasRole(Role::SUPER_ADMIN, 'admin')) {
            // Exclude viewer's own tasks (assignee or submitter cannot review own work)
            $query->where('tasks.assigned_to_id', '!=', $actor->id)
                ->where(function ($sub) use ($actor) {
                    $sub->whereNull('tasks.submitted_by_id')
                        ->orWhere('tasks.submitted_by_id', '!=', $actor->id);
                });

            $query->whereHas('project', function ($p) use ($actor) {
                $p->where(function ($sub) use ($actor) {
                    if ($actor->department_id !== null && $actor->scopeFor('tasks.manage') === Permission::SCOPE_DEPARTMENT) {
                        $sub->where('projects.department_id', $actor->department_id);
                    }
                    $sub->orWhere('projects.manager_id', $actor->id)
                        ->orWhere('projects.team_lead_id', $actor->id);
                });
            });
        }

        if ($request->filled('project_id')) {
            $query->where('tasks.project_id', (int) $request->query('project_id'));
        }

        if ($request->filled('assigned_to_id')) {
            $query->where('tasks.assigned_to_id', (int) $request->query('assigned_to_id'));
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $tasks = $query->orderByRaw('submitted_at IS NULL, submitted_at ASC')
            ->orderBy('due_date', 'asc')
            ->paginate($perPage);

        return response()->json([
            'data' => TaskResource::collection($tasks),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page'    => $tasks->lastPage(),
                'per_page'     => $tasks->perPage(),
                'total'        => $tasks->total(),
            ],
            'message' => 'Review queue loaded successfully.',
        ]);
    }

    /**
     * GET /tasks/{task}/activities
     * List activity timeline for a specific task.
     */
    public function activities(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        $query = $task->activities()->with('user:id,name,email');

        if ($request->filled('action')) {
            $actions = array_filter(explode(',', (string) $request->query('action')));
            $query->whereIn('action', $actions);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->query('to_date'));
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $activities = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'data' => ProjectActivityResource::collection($activities),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page'    => $activities->lastPage(),
                'per_page'     => $activities->perPage(),
                'total'        => $activities->total(),
            ],
            'message' => 'Task activities retrieved successfully.',
        ]);
    }
}
