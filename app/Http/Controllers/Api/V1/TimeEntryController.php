<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TimeEntryRequest;
use App\Http\Resources\TimeEntryResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AccessControl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TimeEntryController extends Controller
{
    private const WITH = [
        'user:id,name,email,employee_code',
        'task:id,title,status',
        'project:id,name,code',
    ];

    public function __construct(private AccessControl $access)
    {
    }

    /**
     * GET /time-entries
     * List time tracking entries with scoping, pagination, date presets, and totals.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = $this->access->constrainTimeEntries(TimeEntry::query(), $actor, 'time.view')
            ->with(self::WITH);

        if ($request->filled('project_id')) {
            $query->where('time_entries.project_id', (int) $request->query('project_id'));
        }

        if ($request->filled('task_id')) {
            $query->where('time_entries.task_id', (int) $request->query('task_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('time_entries.user_id', (int) $request->query('user_id'));
        }

        if ($request->has('is_manual')) {
            $query->where('time_entries.is_manual', $request->boolean('is_manual'));
        }

        // Date Presets
        if ($request->filled('preset')) {
            $preset = $request->query('preset');
            $now = Carbon::now();
            match ($preset) {
                'today'      => $query->whereDate('time_entries.started_at', $now->toDateString()),
                'yesterday'  => $query->whereDate('time_entries.started_at', $now->copy()->subDay()->toDateString()),
                'this_week'  => $query->whereBetween('time_entries.started_at', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]),
                'last_week'  => $query->whereBetween('time_entries.started_at', [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()]),
                'this_month' => $query->whereBetween('time_entries.started_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]),
                'last_month' => $query->whereBetween('time_entries.started_at', [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()]),
                default      => null,
            };
        } else {
            if ($request->filled('date_from')) {
                $query->where('time_entries.started_at', '>=', Carbon::parse($request->query('date_from'))->startOfDay());
            }

            if ($request->filled('date_to')) {
                $query->where('time_entries.started_at', '<=', Carbon::parse($request->query('date_to'))->endOfDay());
            }
        }

        $query->orderByDesc('time_entries.started_at')->orderByDesc('time_entries.id');

        // Total duration for the filtered entries
        $totalSeconds = (int) (clone $query)->whereNotNull('ended_at')->sum('duration_seconds');
        $totalHours = round($totalSeconds / 3600, 2);

        // Export shape if requested
        if ($request->boolean('export')) {
            $allEntries = $query->limit(1000)->get();
            $exportData = $allEntries->map(fn ($entry) => [
                'id'              => $entry->id,
                'user_name'       => $entry->user?->name,
                'employee_code'   => $entry->user?->employee_code,
                'project_name'    => $entry->project?->name,
                'project_code'    => $entry->project?->code,
                'task_title'      => $entry->task?->title,
                'started_at'      => $entry->started_at?->toIso8601String(),
                'ended_at'        => $entry->ended_at?->toIso8601String(),
                'duration_hours'  => $entry->duration_hours,
                'is_manual'       => $entry->is_manual,
                'is_auto_stopped' => $entry->is_auto_stopped,
                'description'     => $entry->description,
            ]);

            return response()->json([
                'data'    => $exportData,
                'summary' => [
                    'total_seconds' => $totalSeconds,
                    'total_hours'   => $totalHours,
                    'count'         => $allEntries->count(),
                ],
            ]);
        }

        // Pagination if requested
        if ($request->has('per_page') || $request->has('page')) {
            $perPage = max(1, min(100, (int) $request->query('per_page', 50)));
            $paginator = $query->paginate($perPage);

            return response()->json([
                'data'    => TimeEntryResource::collection($paginator->items()),
                'summary' => [
                    'total_seconds' => $totalSeconds,
                    'total_hours'   => $totalHours,
                ],
                'links'   => [
                    'first' => $paginator->url(1),
                    'last'  => $paginator->url($paginator->lastPage()),
                    'prev'  => $paginator->previousPageUrl(),
                    'next'  => $paginator->nextPageUrl(),
                ],
                'meta'    => [
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

        $entries = $query->limit(100)->get();

        return response()->json([
            'data'    => TimeEntryResource::collection($entries),
            'summary' => [
                'total_seconds' => $totalSeconds,
                'total_hours'   => $totalHours,
            ],
        ]);
    }

    /**
     * GET /time-entries/active
     * Get the active running timer for the authenticated user (if any).
     */
    public function activeTimer(Request $request): JsonResponse
    {
        $actor = $request->user();

        $active = TimeEntry::with(self::WITH)
            ->where('user_id', $actor->id)
            ->running()
            ->first();

        return response()->json([
            'data' => $active ? TimeEntryResource::make($active) : null,
        ]);
    }

    /**
     * POST /tasks/{task}/timer/start
     * Start a live timer on a task with lock to prevent race conditions.
     */
    public function startTimer(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canTrackTime($actor, $task)) {
            return response()->json([
                'message' => 'You do not have permission to track time on this task.',
            ], 403);
        }

        if (! $task->project->acceptsWork()) {
            return response()->json([
                'message' => "Project status [{$task->project->status}] does not accept time tracking.",
            ], 422);
        }

        // Timers cannot start on tasks in review or completed status
        if (in_array($task->status, [Task::STATUS_REVIEW, Task::STATUS_COMPLETED], true)) {
            return response()->json([
                'message' => "Cannot start timer on a task that is in [{$task->status}] status.",
            ], 422);
        }

        $entry = DB::transaction(function () use ($task, $actor, $request) {
            // Pessimistic lock on the user record to prevent race conditions from concurrent start requests
            User::where('id', $actor->id)->lockForUpdate()->first();

            // Check if user already has an active timer running on this exact task
            $existing = $task->activeTimerFor($actor->id);
            if ($existing) {
                // If paused, unpause/resume it
                if ($existing->is_paused) {
                    $existing->resume();
                }
                return $existing;
            }

            // Stop any other currently running timers for this user in this company
            $runningTimers = TimeEntry::where('user_id', $actor->id)->running()->get();
            foreach ($runningTimers as $running) {
                $running->stop();
            }

            // Auto-transition task to in_progress if backlog, assigned, or changes_required
            if (in_array($task->status, [Task::STATUS_BACKLOG, Task::STATUS_ASSIGNED, Task::STATUS_CHANGES_REQUIRED], true)) {
                $prevStatus = $task->status; // Capture old status BEFORE update to avoid false log

                $task->update([
                    'status'     => Task::STATUS_IN_PROGRESS,
                    'started_at' => $task->started_at ?? now(),
                ]);

                $task->project->recordActivity(
                    action: 'task_status_changed',
                    description: "Task '{$task->title}' automatically transitioned to in_progress upon timer start.",
                    userId: $actor->id,
                    field: 'status',
                    oldValue: $prevStatus,
                    newValue: Task::STATUS_IN_PROGRESS,
                    taskId: $task->id,
                );
            }

            $newEntry = TimeEntry::create([
                'company_id'  => $task->company_id,
                'project_id'  => $task->project_id,
                'task_id'     => $task->id,
                'user_id'     => $actor->id,
                'started_at'  => now(),
                'is_manual'   => false,
                'description' => $request->input('description'),
            ]);

            $task->project->recordActivity(
                action: 'timer_started',
                description: "Timer started on task '{$task->title}' by {$actor->name}.",
                userId: $actor->id,
                taskId: $task->id,
            );

            return $newEntry;
        });

        return response()->json([
            'message' => 'Timer started successfully.',
            'data'    => TimeEntryResource::make($entry->load(self::WITH)),
        ], 201);
    }

    /**
     * POST /tasks/{task}/timer/stop
     * Stop the live running timer on a task.
     */
    public function stopTimer(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        // Find running timer for current user on this task
        $entry = $task->timeEntries()->running()->where('user_id', $actor->id)->first();

        // If not own timer, check if actor has permission to manage the task
        if (! $entry) {
            if ($this->access->canManageTask($actor, $task)) {
                $entry = $task->timeEntries()->running()->first();
            }
        }

        if (! $entry) {
            return response()->json([
                'message' => 'No active running timer found on this task.',
            ], 404);
        }

        DB::transaction(function () use ($entry, $task, $actor, $request) {
            if ($request->filled('description')) {
                $entry->description = $request->input('description');
            }

            $entry->stop();

            $task->project->recordActivity(
                action: 'timer_stopped',
                description: "Timer stopped on task '{$task->title}' by {$actor->name} ({$entry->duration_hours}h).",
                userId: $actor->id,
                taskId: $task->id,
            );
        });

        return response()->json([
            'message' => 'Timer stopped successfully.',
            'data'    => TimeEntryResource::make($entry->fresh(self::WITH)),
        ]);
    }

    /**
     * POST /tasks/{task}/timer/pause
     * Pause the running timer on a task.
     */
    public function pauseTimer(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        $entry = $task->timeEntries()->running()->where('user_id', $actor->id)->first();
        if (! $entry && $this->access->canManageTask($actor, $task)) {
            $entry = $task->timeEntries()->running()->first();
        }

        if (! $entry) {
            return response()->json(['message' => 'No active running timer found to pause.'], 404);
        }

        if ($entry->is_paused) {
            return response()->json([
                'message' => 'Timer is already paused.',
                'data'    => TimeEntryResource::make($entry->load(self::WITH)),
            ]);
        }

        $entry->pause();

        $task->project->recordActivity(
            action: 'timer_paused',
            description: "Timer paused on task '{$task->title}' by {$actor->name}.",
            userId: $actor->id,
            taskId: $task->id,
        );

        return response()->json([
            'message' => 'Timer paused successfully.',
            'data'    => TimeEntryResource::make($entry->fresh(self::WITH)),
        ]);
    }

    /**
     * POST /tasks/{task}/timer/resume
     * Resume a paused timer on a task.
     */
    public function resumeTimer(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        $entry = $task->timeEntries()->running()->where('user_id', $actor->id)->first();
        if (! $entry && $this->access->canManageTask($actor, $task)) {
            $entry = $task->timeEntries()->running()->first();
        }

        if (! $entry) {
            return response()->json(['message' => 'No active timer found to resume.'], 404);
        }

        if (! $entry->is_paused) {
            return response()->json([
                'message' => 'Timer is already active and running.',
                'data'    => TimeEntryResource::make($entry->load(self::WITH)),
            ]);
        }

        $entry->resume();

        $task->project->recordActivity(
            action: 'timer_resumed',
            description: "Timer resumed on task '{$task->title}' by {$actor->name}.",
            userId: $actor->id,
            taskId: $task->id,
        );

        return response()->json([
            'message' => 'Timer resumed successfully.',
            'data'    => TimeEntryResource::make($entry->fresh(self::WITH)),
        ]);
    }

    /**
     * POST /tasks/{task}/time-entries
     * Log manual time entry.
     */
    public function storeManual(TimeEntryRequest $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canTrackTime($actor, $task) && ! $this->access->canManageTask($actor, $task)) {
            return response()->json([
                'message' => 'You do not have permission to log time on this task.',
            ], 403);
        }

        if (! $task->project->acceptsWork()) {
            return response()->json([
                'message' => "Project status [{$task->project->status}] does not accept time entries.",
            ], 422);
        }

        $startedAt = Carbon::parse($request->input('started_at'));
        $endedAt = Carbon::parse($request->input('ended_at'));
        $durationSeconds = max(0, (int) $startedAt->diffInSeconds($endedAt));

        $hasOverlap = $request->attributes->get('has_overlap_warning', false);

        $entry = DB::transaction(function () use ($task, $actor, $request, $startedAt, $endedAt, $durationSeconds) {
            $entry = TimeEntry::create([
                'company_id'       => $task->company_id,
                'project_id'       => $task->project_id,
                'task_id'          => $task->id,
                'user_id'          => $actor->id,
                'started_at'       => $startedAt,
                'ended_at'         => $endedAt,
                'duration_seconds' => $durationSeconds,
                'description'      => $request->input('description'),
                'is_manual'        => true,
            ]);

            $task->recalculateActualHours();

            $task->project->recordActivity(
                action: 'time_logged',
                description: "Manual time entry logged on task '{$task->title}' by {$actor->name} ({$entry->duration_hours}h).",
                userId: $actor->id,
                taskId: $task->id,
            );

            return $entry;
        });

        $res = [
            'message' => 'Time logged successfully.',
            'data'    => TimeEntryResource::make($entry->load(self::WITH)),
        ];

        if ($hasOverlap) {
            $res['warning'] = 'Note: This time entry overlaps with an existing logged entry.';
        }

        return response()->json($res, 201);
    }

    /**
     * DELETE /time-entries/{entry}
     * Delete a time tracking entry.
     * Enforces acceptsWork(), blocks deleting running timers, and requires reason on completed tasks.
     */
    public function destroy(Request $request, TimeEntry $entry): JsonResponse
    {
        $actor = $request->user();

        // Check project accepts work
        if (! $entry->project->acceptsWork()) {
            return response()->json([
                'message' => "Project status [{$entry->project->status}] does not accept time entry deletion.",
            ], 422);
        }

        // Cannot delete an active running timer
        if ($entry->is_running) {
            return response()->json([
                'message' => 'Cannot delete an active running timer. Stop the timer first.',
            ], 422);
        }

        $isTaskCompleted = $entry->task && $entry->task->status === Task::STATUS_COMPLETED;

        // If task is completed: only Manager or Team Lead can delete, with a required reason
        if ($isTaskCompleted) {
            if (! $this->access->canManageTask($actor, $entry->task)) {
                return response()->json([
                    'message' => 'Only a Manager or Team Lead can delete time entries on a completed task.',
                ], 403);
            }

            if (! $request->filled('reason')) {
                return response()->json([
                    'message' => 'A reason is required to delete a time entry on a completed task.',
                ], 422);
            }
        } else {
            $canDelete = (int) $entry->user_id === (int) $actor->id
                || $this->access->canManageTask($actor, $entry->task);

            if (! $canDelete) {
                return response()->json([
                    'message' => 'You do not have permission to delete this time entry.',
                ], 403);
            }
        }

        $reason = $request->input('reason');

        DB::transaction(function () use ($entry, $actor, $reason) {
            $task = $entry->task;

            $entry->delete();

            if ($task) {
                $task->recalculateActualHours();

                $desc = "Time entry deleted from task '{$task->title}' by {$actor->name}."
                    . ($reason ? " Reason: {$reason}" : '');

                $task->project->recordActivity(
                    action: 'time_deleted',
                    description: $desc,
                    userId: $actor->id,
                    reason: $reason,
                    taskId: $task->id,
                );
            }
        });

        return response()->json([
            'message' => 'Time entry deleted successfully.',
        ]);
    }

    /**
     * GET /timesheet
     * Daily and weekly time tracking totals for user.
     */
    public function timesheet(Request $request): JsonResponse
    {
        $actor = $request->user();

        $targetUserId = $actor->id;
        if ($request->filled('user_id')) {
            $requestedId = (int) $request->query('user_id');
            if ($requestedId !== $actor->id) {
                if (! $actor->hasPermission('reports.view') && ! $actor->isAdmin) {
                    return response()->json(['message' => 'Unauthorized timesheet access.'], 403);
                }
                $targetUserId = $requestedId;
            }
        }

        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->query('date_from'))->startOfDay()
            : Carbon::now()->startOfWeek();

        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->query('date_to'))->endOfDay()
            : Carbon::now()->endOfWeek();

        $entries = TimeEntry::with(['task:id,title', 'project:id,name,code'])
            ->where('user_id', $targetUserId)
            ->completed()
            ->whereBetween('started_at', [$dateFrom, $dateTo])
            ->orderBy('started_at')
            ->get();

        // Group by date
        $days = [];
        $cursor = $dateFrom->copy()->startOfDay();
        $endCursor = $dateTo->copy()->startOfDay();

        while ($cursor->lte($endCursor)) {
            $dateStr = $cursor->toDateString();
            $dayEntries = $entries->filter(fn ($e) => $e->started_at->toDateString() === $dateStr);
            $daySeconds = (int) $dayEntries->sum('duration_seconds');

            $days[] = [
                'date'             => $dateStr,
                'day_name'         => $cursor->format('l'),
                'hours'            => round($daySeconds / 3600, 2),
                'seconds'          => $daySeconds,
                'entries_count'    => $dayEntries->count(),
                'tasks'            => $dayEntries->map(fn ($e) => [
                    'task_id'         => $e->task_id,
                    'task_title'      => $e->task?->title,
                    'project_name'    => $e->project?->name,
                    'duration_hours'  => $e->duration_hours,
                    'is_manual'       => $e->is_manual,
                ])->values()->all(),
            ];

            $cursor->addDay();
        }

        $totalSeconds = (int) $entries->sum('duration_seconds');

        return response()->json([
            'data' => [
                'user_id'      => $targetUserId,
                'date_from'    => $dateFrom->toDateString(),
                'date_to'      => $dateTo->toDateString(),
                'total_hours'  => round($totalSeconds / 3600, 2),
                'total_seconds'=> $totalSeconds,
                'days'         => $days,
            ],
        ]);
    }

    /**
     * GET /timesheet/team
     * Team time records summary for Team Leads and Managers.
     */
    public function teamTimesheet(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor->hasPermission('reports.view') && ! $actor->isAdmin && ! $actor->isManager && ! $actor->isTeamLead) {
            return response()->json(['message' => 'Unauthorized team timesheet access.'], 403);
        }

        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->query('date_from'))->startOfDay()
            : Carbon::now()->startOfWeek();

        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->query('date_to'))->endOfDay()
            : Carbon::now()->endOfWeek();

        $query = $this->access->constrainTimeEntries(TimeEntry::query(), $actor, 'time.view')
            ->with(['user:id,name,email,employee_code', 'task:id,title', 'project:id,name,code'])
            ->completed()
            ->whereBetween('started_at', [$dateFrom, $dateTo]);

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }

        $entries = $query->get();

        // Group by employee
        $byUser = $entries->groupBy('user_id')->map(function ($userEntries) {
            $first = $userEntries->first();
            $seconds = (int) $userEntries->sum('duration_seconds');

            return [
                'user'          => [
                    'id'            => $first->user_id,
                    'name'          => $first->user?->name,
                    'email'         => $first->user?->email,
                    'employee_code' => $first->user?->employee_code,
                ],
                'total_hours'   => round($seconds / 3600, 2),
                'total_seconds' => $seconds,
                'entries_count' => $userEntries->count(),
            ];
        })->values()->all();

        $totalSeconds = (int) $entries->sum('duration_seconds');

        return response()->json([
            'data' => [
                'date_from'    => $dateFrom->toDateString(),
                'date_to'      => $dateTo->toDateString(),
                'total_hours'  => round($totalSeconds / 3600, 2),
                'members'      => $byUser,
            ],
        ]);
    }
}
