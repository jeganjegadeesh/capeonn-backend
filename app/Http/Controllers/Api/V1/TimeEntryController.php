<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TimeEntryRequest;
use App\Http\Resources\TimeEntryResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\AccessControl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TimeEntryController extends Controller
{
    private const WITH = [
        'user:id,name,email,employee_code,avatar_url',
        'task:id,title,status',
        'project:id,name,code',
    ];

    public function __construct(private AccessControl $access)
    {
    }

    /**
     * GET /time-entries
     * List time tracking entries with scoping and filters.
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

        if ($request->filled('date_from')) {
            $query->where('time_entries.started_at', '>=', Carbon::parse($request->query('date_from'))->startOfDay());
        }

        if ($request->filled('date_to')) {
            $query->where('time_entries.started_at', '<=', Carbon::parse($request->query('date_to'))->endOfDay());
        }

        $entries = $query->orderByDesc('started_at')->limit(100)->get();

        return response()->json([
            'data' => TimeEntryResource::collection($entries),
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
     * Start a live timer on a task.
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

        // Check if user already has an active timer running on this exact task
        $existing = $task->activeTimerFor($actor->id);
        if ($existing) {
            return response()->json([
                'message' => 'Timer is already running on this task.',
                'data'    => TimeEntryResource::make($existing->load(self::WITH)),
            ]);
        }

        $entry = DB::transaction(function () use ($task, $actor, $request) {
            // Stop any other currently running timers for this user in this company
            $runningTimers = TimeEntry::where('user_id', $actor->id)->running()->get();
            foreach ($runningTimers as $running) {
                $running->stop();
            }

            // Auto-transition task to in_progress if backlog or assigned
            if (in_array($task->status, [Task::STATUS_BACKLOG, Task::STATUS_ASSIGNED], true)) {
                $task->update([
                    'status'     => Task::STATUS_IN_PROGRESS,
                    'started_at' => $task->started_at ?? now(),
                ]);

                $task->project->recordActivity(
                    action: 'task_status_changed',
                    description: "Task '{$task->title}' automatically transitioned to in_progress upon timer start.",
                    userId: $actor->id,
                    field: 'status',
                    oldValue: $task->status,
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

        return response()->json([
            'message' => 'Time logged successfully.',
            'data'    => TimeEntryResource::make($entry->load(self::WITH)),
        ], 201);
    }

    /**
     * DELETE /time-entries/{entry}
     * Delete a time tracking entry.
     */
    public function destroy(Request $request, TimeEntry $entry): JsonResponse
    {
        $actor = $request->user();

        $canDelete = (int) $entry->user_id === (int) $actor->id
            || $this->access->canManageTask($actor, $entry->task);

        if (! $canDelete) {
            return response()->json([
                'message' => 'You do not have permission to delete this time entry.',
            ], 403);
        }

        DB::transaction(function () use ($entry, $actor) {
            $task = $entry->task;

            $entry->delete();

            if ($task) {
                $task->recalculateActualHours();
                $task->project->recordActivity(
                    action: 'time_deleted',
                    description: "Time entry deleted from task '{$task->title}' by {$actor->name}.",
                    userId: $actor->id,
                    taskId: $task->id,
                );
            }
        });

        return response()->json([
            'message' => 'Time entry deleted successfully.',
        ]);
    }
}
