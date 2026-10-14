<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskReview;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportService
{
    public function __construct(private AccessControl $access)
    {
    }

    /**
     * Parse date range filters supporting presets and custom dates.
     *
     * @return array{start: ?Carbon, end: ?Carbon}
     */
    public function parseDateRange(array $filters): array
    {
        $preset = $filters['preset'] ?? null;
        $now = Carbon::now();

        if ($preset) {
            return match ($preset) {
                'today' => [
                    'start' => $now->copy()->startOfDay(),
                    'end'   => $now->copy()->endOfDay(),
                ],
                'this_week' => [
                    'start' => $now->copy()->startOfWeek(),
                    'end'   => $now->copy()->endOfWeek(),
                ],
                'last_week' => [
                    'start' => $now->copy()->subWeek()->startOfWeek(),
                    'end'   => $now->copy()->subWeek()->endOfWeek(),
                ],
                'this_month' => [
                    'start' => $now->copy()->startOfMonth(),
                    'end'   => $now->copy()->endOfMonth(),
                ],
                'last_month' => [
                    'start' => $now->copy()->subMonth()->startOfMonth(),
                    'end'   => $now->copy()->subMonth()->endOfMonth(),
                ],
                'this_quarter' => [
                    'start' => $now->copy()->firstOfQuarter()->startOfDay(),
                    'end'   => $now->copy()->lastOfQuarter()->endOfDay(),
                ],
                'this_year' => [
                    'start' => $now->copy()->startOfYear(),
                    'end'   => $now->copy()->endOfYear(),
                ],
                default => ['start' => null, 'end' => null],
            };
        }

        $start = ! empty($filters['start_date']) ? Carbon::parse($filters['start_date'])->startOfDay() : null;
        $end = ! empty($filters['end_date']) ? Carbon::parse($filters['end_date'])->endOfDay() : null;

        return ['start' => $start, 'end' => $end];
    }

    /**
     * 1. Manager & Executive Overview Dashboard
     */
    public function getDashboardSummary(User $actor, array $filters = []): array
    {
        $today = Carbon::today()->toDateString();
        $dateRange = $this->parseDateRange($filters);

        // Scoped Base Queries
        $projectQuery = $this->access->constrainProjects(Project::query(), $actor, 'reports.view');
        $taskQuery = $this->access->constrainTasks(Task::query(), $actor, 'reports.view');
        $userQuery = $this->access->constrainUsers(User::query(), $actor, 'reports.view');

        if (! empty($filters['department_id'])) {
            $deptId = (int) $filters['department_id'];
            $projectQuery->where('projects.department_id', $deptId);
            $taskQuery->whereHas('project', fn ($p) => $p->where('department_id', $deptId));
            $userQuery->where('users.department_id', $deptId);
        }

        if (! empty($filters['project_id'])) {
            $projId = (int) $filters['project_id'];
            $projectQuery->where('projects.id', $projId);
            $taskQuery->where('tasks.project_id', $projId);
        }

        // Projects Aggregate
        $projectsAgg = (clone $projectQuery)
            ->selectRaw("
                COUNT(*) as total_projects,
                COUNT(CASE WHEN status IN ('active', 'in_progress') THEN 1 END) as active_projects,
                COUNT(CASE WHEN status IN ('planned', 'planning') THEN 1 END) as planned_projects,
                COUNT(CASE WHEN status = 'on_hold' THEN 1 END) as on_hold_projects,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_projects,
                COUNT(CASE WHEN status NOT IN ('completed', 'archived', 'cancelled') AND deadline IS NOT NULL AND deadline < ? THEN 1 END) as overdue_projects,
                COUNT(CASE WHEN status NOT IN ('completed', 'archived', 'cancelled') AND deadline IS NOT NULL AND deadline >= ? AND deadline <= ? THEN 1 END) as due_soon_projects
            ", [$today, $today, Carbon::today()->addDays(7)->toDateString()])
            ->first();

        // Tasks Aggregate
        $taskAggQuery = clone $taskQuery;
        if ($dateRange['start'] && $dateRange['end']) {
            $taskAggQuery->whereBetween('tasks.created_at', [$dateRange['start'], $dateRange['end']]);
        }

        $tasksAgg = $taskAggQuery
            ->selectRaw("
                COUNT(*) as total_tasks,
                COUNT(CASE WHEN status = 'backlog' THEN 1 END) as backlog_tasks,
                COUNT(CASE WHEN status = 'assigned' THEN 1 END) as assigned_tasks,
                COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_tasks,
                COUNT(CASE WHEN status = 'review' THEN 1 END) as review_tasks,
                COUNT(CASE WHEN status = 'changes_required' THEN 1 END) as changes_required_tasks,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_tasks,
                COUNT(CASE WHEN status != 'completed' AND due_date IS NOT NULL AND due_date < ? THEN 1 END) as overdue_tasks,
                COALESCE(SUM(estimated_hours), 0) as total_estimated_hours,
                COALESCE(SUM(actual_hours), 0) as total_actual_hours
            ", [$today])
            ->first();

        $totalTasks = (int) ($tasksAgg->total_tasks ?? 0);
        $completedTasks = (int) ($tasksAgg->completed_tasks ?? 0);
        $completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0.0;

        // On-Time Delivery Rate
        $completedOnTime = (clone $taskQuery)
            ->where('status', Task::STATUS_COMPLETED)
            ->where(function ($q) {
                $q->whereNull('due_date')
                    ->orWhereColumn('completed_at', '<=', 'due_date');
            })
            ->count();

        $onTimeRate = $completedTasks > 0 ? round(($completedOnTime / $completedTasks) * 100, 1) : 0.0;

        // Tracked Working Hours from Time Entries
        $timeQuery = $this->access->constrainTimeEntries(TimeEntry::query(), $actor, 'reports.view');
        if (! empty($filters['department_id'])) {
            $deptId = (int) $filters['department_id'];
            $timeQuery->whereHas('project', fn ($p) => $p->where('department_id', $deptId));
        }
        if (! empty($filters['project_id'])) {
            $timeQuery->where('time_entries.project_id', (int) $filters['project_id']);
        }
        if ($dateRange['start'] && $dateRange['end']) {
            $timeQuery->whereBetween('started_at', [$dateRange['start'], $dateRange['end']]);
        }
        $totalTrackedSeconds = (int) $timeQuery->sum('duration_seconds');
        $totalTrackedHours = round($totalTrackedSeconds / 3600, 2);

        // Workload distribution summary count
        $workloadStats = $this->computeWorkloadCounts($userQuery);

        // Critical Attention Items
        $urgentOverdue = (clone $taskQuery)
            ->where('status', '!=', Task::STATUS_COMPLETED)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->with(['project:id,name,code', 'assignedTo:id,name,email'])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('due_date')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'id'            => $t->id,
                'title'         => $t->title,
                'priority'      => $t->priority,
                'due_date'      => $t->due_date?->toDateString(),
                'days_overdue'  => (int) abs(Carbon::parse($t->due_date)->diffInDays(Carbon::today(), false)),
                'project_name'  => $t->project?->name,
                'assignee_name' => $t->assignedTo?->name ?? 'Unassigned',
            ]);

        $dueSoonProjects = (clone $projectQuery)
            ->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED, Project::STATUS_CANCELLED])
            ->whereNotNull('deadline')
            ->where('deadline', '>=', $today)
            ->where('deadline', '<=', Carbon::today()->addDays(7)->toDateString())
            ->with(['department:id,name', 'teamLead:id,name'])
            ->orderBy('deadline')
            ->limit(5)
            ->get()
            ->map(fn ($p) => [
                'id'             => $p->id,
                'name'           => $p->name,
                'deadline'       => $p->deadline?->toDateString(),
                'days_remaining' => (int) Carbon::today()->diffInDays(Carbon::parse($p->deadline), false),
                'team_lead_name' => $p->teamLead?->name,
                'department'     => $p->department?->name,
            ]);

        $stuckReviews = (clone $taskQuery)
            ->where('status', Task::STATUS_REVIEW)
            ->where('submitted_at', '<', Carbon::now()->subHours(48))
            ->with(['project:id,name', 'assignedTo:id,name', 'reviewer:id,name'])
            ->orderBy('submitted_at')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'id'            => $t->id,
                'title'         => $t->title,
                'submitted_at'  => $t->submitted_at?->toIso8601String(),
                'hours_pending' => (int) Carbon::parse($t->submitted_at)->diffInHours(Carbon::now()),
                'project_name'  => $t->project?->name,
                'assignee_name' => $t->assignedTo?->name,
                'reviewer_name' => $t->reviewer?->name,
            ]);

        return [
            'projects' => [
                'total'       => (int) ($projectsAgg->total_projects ?? 0),
                'active'      => (int) ($projectsAgg->active_projects ?? 0),
                'planned'     => (int) ($projectsAgg->planned_projects ?? 0),
                'on_hold'     => (int) ($projectsAgg->on_hold_projects ?? 0),
                'completed'   => (int) ($projectsAgg->completed_projects ?? 0),
                'overdue'     => (int) ($projectsAgg->overdue_projects ?? 0),
                'due_soon'    => (int) ($projectsAgg->due_soon_projects ?? 0),
            ],
            'tasks' => [
                'total'            => $totalTasks,
                'backlog'          => (int) ($tasksAgg->backlog_tasks ?? 0),
                'assigned'         => (int) ($tasksAgg->assigned_tasks ?? 0),
                'in_progress'      => (int) ($tasksAgg->in_progress_tasks ?? 0),
                'review'           => (int) ($tasksAgg->review_tasks ?? 0),
                'changes_required' => (int) ($tasksAgg->changes_required_tasks ?? 0),
                'completed'        => $completedTasks,
                'overdue'          => (int) ($tasksAgg->overdue_tasks ?? 0),
                'completion_rate'  => $completionRate,
                'on_time_rate'     => $onTimeRate,
            ],
            'hours' => [
                'estimated_hours' => round((float) ($tasksAgg->total_estimated_hours ?? 0), 2),
                'actual_hours'    => round((float) ($tasksAgg->total_actual_hours ?? 0), 2),
                'tracked_hours'   => $totalTrackedHours,
            ],
            'workload_summary' => $workloadStats,
            'attention' => [
                'urgent_overdue_tasks' => $urgentOverdue,
                'due_soon_projects'    => $dueSoonProjects,
                'stuck_reviews'        => $stuckReviews,
            ],
        ];
    }

    /**
     * 2. Employee Workload Report
     */
    public function getEmployeeWorkload(User $actor, array $filters = []): array
    {
        $userQuery = $this->access->constrainUsers(User::query(), $actor, 'reports.view')
            ->where('is_active', true)
            ->with(['department:id,name,code', 'designation:id,name']);

        if (! empty($filters['department_id'])) {
            $userQuery->where('users.department_id', (int) $filters['department_id']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $userQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }

        $users = $userQuery->orderBy('name')->get();
        $startOfWeek = Carbon::now()->startOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();

        $rows = [];
        $overloadedCount = 0;
        $nearCapacityCount = 0;
        $normalCount = 0;
        $underutilizedCount = 0;

        foreach ($users as $u) {
            $activeTasks = Task::where('assigned_to_id', $u->id)
                ->where('status', '!=', Task::STATUS_COMPLETED)
                ->selectRaw("
                    COUNT(*) as active_count,
                    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_count,
                    COUNT(CASE WHEN status = 'review' THEN 1 END) as in_review_count,
                    COALESCE(SUM(estimated_hours), 0) as backlog_hours
                ")
                ->first();

            $activeCount = (int) ($activeTasks->active_count ?? 0);
            $inProgressCount = (int) ($activeTasks->in_progress_count ?? 0);
            $inReviewCount = (int) ($activeTasks->in_review_count ?? 0);
            $backlogHours = round((float) ($activeTasks->backlog_hours ?? 0), 2);

            $weekSeconds = (int) TimeEntry::where('user_id', $u->id)
                ->where('started_at', '>=', $startOfWeek)
                ->sum('duration_seconds');
            $weekHours = round($weekSeconds / 3600, 2);

            $monthSeconds = (int) TimeEntry::where('user_id', $u->id)
                ->where('started_at', '>=', $startOfMonth)
                ->sum('duration_seconds');
            $monthHours = round($monthSeconds / 3600, 2);

            // Classification
            if ($activeCount > 5 || $backlogHours > 40) {
                $status = 'overloaded';
                $overloadedCount++;
            } elseif ($activeCount === 5 || $backlogHours > 35) {
                $status = 'near_capacity';
                $nearCapacityCount++;
            } elseif ($activeCount >= 1) {
                $status = 'normal';
                $normalCount++;
            } else {
                $status = 'underutilized';
                $underutilizedCount++;
            }

            $rows[] = [
                'user_id'                 => $u->id,
                'name'                    => $u->name,
                'email'                   => $u->email,
                'employee_code'           => $u->employee_code,
                'department_id'           => $u->department_id,
                'department_name'         => $u->department?->name,
                'designation_name'        => $u->designation?->name,
                'active_tasks_count'      => $activeCount,
                'in_progress_tasks_count' => $inProgressCount,
                'in_review_tasks_count'   => $inReviewCount,
                'backlog_hours'           => $backlogHours,
                'logged_hours_this_week'  => $weekHours,
                'logged_hours_this_month' => $monthHours,
                'workload_status'         => $status,
            ];
        }

        return [
            'summary' => [
                'total_employees'     => count($rows),
                'overloaded_count'    => $overloadedCount,
                'near_capacity_count' => $nearCapacityCount,
                'normal_count'        => $normalCount,
                'underutilized_count' => $underutilizedCount,
            ],
            'items' => $rows,
        ];
    }

    /**
     * 3. Task Completion Report
     */
    public function getTaskCompletionReport(User $actor, array $filters = []): array
    {
        $taskQuery = $this->access->constrainTasks(Task::query(), $actor, 'reports.view');
        $dateRange = $this->parseDateRange($filters);

        if (! empty($filters['department_id'])) {
            $deptId = (int) $filters['department_id'];
            $taskQuery->whereHas('project', fn ($p) => $p->where('department_id', $deptId));
        }

        if (! empty($filters['project_id'])) {
            $taskQuery->where('tasks.project_id', (int) $filters['project_id']);
        }

        if (! empty($filters['assigned_to_id'])) {
            $taskQuery->where('tasks.assigned_to_id', (int) $filters['assigned_to_id']);
        }

        if ($dateRange['start'] && $dateRange['end']) {
            $taskQuery->whereBetween('tasks.created_at', [$dateRange['start'], $dateRange['end']]);
        }

        // Status Breakdown
        $statusCounts = (clone $taskQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $totalTasks = (int) (clone $taskQuery)->count();
        $completedTasks = (int) ($statusCounts[Task::STATUS_COMPLETED] ?? 0);
        $completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0.0;

        // Average Turnaround duration (created_at to completed_at) in hours
        $completedList = (clone $taskQuery)
            ->where('status', Task::STATUS_COMPLETED)
            ->whereNotNull('completed_at')
            ->get(['created_at', 'completed_at']);

        $totalTurnaroundHours = 0;
        foreach ($completedList as $c) {
            $totalTurnaroundHours += Carbon::parse($c->created_at)->diffInHours(Carbon::parse($c->completed_at));
        }
        $avgTurnaroundHours = $completedList->count() > 0 ? round($totalTurnaroundHours / $completedList->count(), 1) : 0.0;

        // Task Review Pass Rate
        $taskIds = (clone $taskQuery)->pluck('tasks.id');
        $reviewStats = TaskReview::whereIn('task_id', $taskIds)
            ->selectRaw("
                COUNT(CASE WHEN outcome = 'approved' AND round_number = 1 THEN 1 END) as first_time_approved,
                COUNT(CASE WHEN outcome = 'changes_required' THEN 1 END) as changes_requested
            ")
            ->first();

        $firstTimeApproved = (int) ($reviewStats->first_time_approved ?? 0);
        $changesRequested = (int) ($reviewStats->changes_requested ?? 0);
        $totalReviewDecisions = $firstTimeApproved + $changesRequested;
        $firstTimePassRate = $totalReviewDecisions > 0 ? round(($firstTimeApproved / $totalReviewDecisions) * 100, 1) : 100.0;

        // Velocity Over Time (last 14 days or grouped within range)
        $rangeStart = $dateRange['start'] ?? Carbon::now()->subDays(13)->startOfDay();
        $rangeEnd = $dateRange['end'] ?? Carbon::now()->endOfDay();

        $completedByDate = (clone $taskQuery)
            ->where('status', Task::STATUS_COMPLETED)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$rangeStart, $rangeEnd])
            ->selectRaw('DATE(completed_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->all();

        $timeline = [];
        $current = $rangeStart->copy();
        while ($current <= $rangeEnd) {
            $d = $current->toDateString();
            $timeline[] = [
                'date'            => $d,
                'completed_count' => (int) ($completedByDate[$d] ?? 0),
            ];
            $current->addDay();
        }

        return [
            'summary' => [
                'total_tasks'            => $totalTasks,
                'completed_tasks'        => $completedTasks,
                'completion_rate'        => $completionRate,
                'avg_turnaround_hours'   => $avgTurnaroundHours,
                'first_time_pass_rate'   => $firstTimePassRate,
                'first_time_approved'    => $firstTimeApproved,
                'changes_requested'      => $changesRequested,
            ],
            'status_distribution' => [
                'backlog'          => (int) ($statusCounts[Task::STATUS_BACKLOG] ?? 0),
                'assigned'         => (int) ($statusCounts[Task::STATUS_ASSIGNED] ?? 0),
                'in_progress'      => (int) ($statusCounts[Task::STATUS_IN_PROGRESS] ?? 0),
                'review'           => (int) ($statusCounts[Task::STATUS_REVIEW] ?? 0),
                'changes_required' => (int) ($statusCounts[Task::STATUS_CHANGES_REQUIRED] ?? 0),
                'completed'        => $completedTasks,
            ],
            'velocity_timeline' => $timeline,
        ];
    }

    /**
     * 4. Estimated vs Actual Time Variance Report
     */
    public function getTimeVarianceReport(User $actor, array $filters = []): array
    {
        $taskQuery = $this->access->constrainTasks(Task::query(), $actor, 'reports.view')
            ->with(['project:id,name,code', 'assignedTo:id,name,email']);

        $dateRange = $this->parseDateRange($filters);
        if ($dateRange['start'] && $dateRange['end']) {
            $taskQuery->whereBetween('tasks.created_at', [$dateRange['start'], $dateRange['end']]);
        }

        if (! empty($filters['department_id'])) {
            $deptId = (int) $filters['department_id'];
            $taskQuery->whereHas('project', fn ($p) => $p->where('department_id', $deptId));
        }

        if (! empty($filters['project_id'])) {
            $taskQuery->where('tasks.project_id', (int) $filters['project_id']);
        }

        if (! empty($filters['assigned_to_id'])) {
            $taskQuery->where('tasks.assigned_to_id', (int) $filters['assigned_to_id']);
        }

        if (! empty($filters['classification'])) {
            $cls = $filters['classification'];
            if ($cls === 'early') {
                $taskQuery->where('tasks.status', Task::STATUS_COMPLETED)
                    ->whereNotNull('tasks.estimated_hours')
                    ->where('tasks.estimated_hours', '>', 0)
                    ->whereColumn('tasks.actual_hours', '<', DB::raw('tasks.estimated_hours * 0.90'));
            } elseif ($cls === 'on_time') {
                $taskQuery->where('tasks.status', Task::STATUS_COMPLETED)
                    ->whereNotNull('tasks.estimated_hours')
                    ->where('tasks.estimated_hours', '>', 0)
                    ->whereColumn('tasks.actual_hours', '>=', DB::raw('tasks.estimated_hours * 0.90'))
                    ->whereColumn('tasks.actual_hours', '<=', DB::raw('tasks.estimated_hours * 1.10'));
            } elseif ($cls === 'over_time') {
                $taskQuery->where('tasks.status', Task::STATUS_COMPLETED)
                    ->whereNotNull('tasks.estimated_hours')
                    ->where('tasks.estimated_hours', '>', 0)
                    ->whereColumn('tasks.actual_hours', '>', DB::raw('tasks.estimated_hours * 1.10'));
            }
        }

        $tasks = (clone $taskQuery)->orderByDesc('tasks.updated_at')->get();

        $totalEstimated = 0.0;
        $totalActual = 0.0;
        $earlyCount = 0;
        $onTimeCount = 0;
        $overTimeCount = 0;
        $items = [];

        foreach ($tasks as $t) {
            $est = (float) ($t->estimated_hours ?? 0);
            $act = (float) ($t->actual_hours ?? 0);
            $totalEstimated += $est;
            $totalActual += $act;

            $varianceHours = round($act - $est, 2);
            $variancePercent = $est > 0 ? round(($varianceHours / $est) * 100, 1) : 0.0;

            $classification = $t->time_variance;
            if ($classification === Task::VARIANCE_EARLY) {
                $earlyCount++;
            } elseif ($classification === Task::VARIANCE_ON_TIME) {
                $onTimeCount++;
            } elseif ($classification === Task::VARIANCE_OVER_TIME) {
                $overTimeCount++;
            }

            $items[] = [
                'task_id'             => $t->id,
                'title'               => $t->title,
                'status'              => $t->status,
                'priority'            => $t->priority,
                'project_id'          => $t->project_id,
                'project_name'        => $t->project?->name,
                'project_code'        => $t->project?->code,
                'assignee_name'       => $t->assignedTo?->name ?? 'Unassigned',
                'estimated_hours'     => $est,
                'actual_hours'        => $act,
                'variance_hours'      => $varianceHours,
                'variance_percentage' => $variancePercent,
                'classification'      => $classification,
            ];
        }

        $netVarianceHours = round($totalActual - $totalEstimated, 2);
        $overallVariancePercent = $totalEstimated > 0 ? round(($netVarianceHours / $totalEstimated) * 100, 1) : 0.0;

        return [
            'summary' => [
                'total_tasks'              => count($items),
                'total_estimated_hours'    => round($totalEstimated, 2),
                'total_actual_hours'       => round($totalActual, 2),
                'net_variance_hours'       => $netVarianceHours,
                'overall_variance_percent' => $overallVariancePercent,
                'early_count'              => $earlyCount,
                'on_time_count'            => $onTimeCount,
                'over_time_count'          => $overTimeCount,
            ],
            'items' => $items,
        ];
    }

    /**
     * 5. Overdue Task Reports & Aging Buckets
     */
    public function getOverdueTasksReport(User $actor, array $filters = []): array
    {
        $today = Carbon::today()->toDateString();
        $taskQuery = $this->access->constrainTasks(Task::query(), $actor, 'reports.view')
            ->where('tasks.status', '!=', Task::STATUS_COMPLETED)
            ->whereNotNull('tasks.due_date')
            ->where('tasks.due_date', '<', $today)
            ->with(['project:id,name,code,department_id', 'assignedTo:id,name,email,employee_code']);

        if (! empty($filters['department_id'])) {
            $deptId = (int) $filters['department_id'];
            $taskQuery->whereHas('project', fn ($p) => $p->where('department_id', $deptId));
        }

        if (! empty($filters['project_id'])) {
            $taskQuery->where('tasks.project_id', (int) $filters['project_id']);
        }

        if (! empty($filters['priority'])) {
            $taskQuery->where('tasks.priority', $filters['priority']);
        }

        if (! empty($filters['assigned_to_id'])) {
            $taskQuery->where('tasks.assigned_to_id', (int) $filters['assigned_to_id']);
        }

        $tasks = $taskQuery->orderBy('tasks.due_date')->get();

        $bucket1to3 = 0;
        $bucket4to7 = 0;
        $bucket8to14 = 0;
        $bucket15Plus = 0;

        $priorityBreakdown = [
            'urgent' => 0,
            'high'   => 0,
            'medium' => 0,
            'low'    => 0,
        ];

        $items = [];
        foreach ($tasks as $t) {
            $daysOverdue = (int) abs(Carbon::parse($t->due_date)->diffInDays(Carbon::today(), false));

            if ($daysOverdue <= 3) {
                $bucket1to3++;
                $bucketLabel = '1-3 days';
            } elseif ($daysOverdue <= 7) {
                $bucket4to7++;
                $bucketLabel = '4-7 days';
            } elseif ($daysOverdue <= 14) {
                $bucket8to14++;
                $bucketLabel = '8-14 days';
            } else {
                $bucket15Plus++;
                $bucketLabel = '15+ days';
            }

            if (isset($priorityBreakdown[$t->priority])) {
                $priorityBreakdown[$t->priority]++;
            }

            $items[] = [
                'task_id'         => $t->id,
                'title'           => $t->title,
                'status'          => $t->status,
                'priority'        => $t->priority,
                'due_date'        => $t->due_date?->toDateString(),
                'days_overdue'    => $daysOverdue,
                'aging_bucket'    => $bucketLabel,
                'estimated_hours' => (float) ($t->estimated_hours ?? 0),
                'actual_hours'    => (float) ($t->actual_hours ?? 0),
                'project_id'      => $t->project_id,
                'project_name'    => $t->project?->name,
                'project_code'    => $t->project?->code,
                'assignee_id'     => $t->assigned_to_id,
                'assignee_name'   => $t->assignedTo?->name ?? 'Unassigned',
                'assignee_code'   => $t->assignedTo?->employee_code,
            ];
        }

        return [
            'summary' => [
                'total_overdue' => count($items),
                'aging_buckets' => [
                    '1_to_3_days'  => $bucket1to3,
                    '4_to_7_days'  => $bucket4to7,
                    '8_to_14_days' => $bucket8to14,
                    '15_plus_days' => $bucket15Plus,
                ],
                'priority_breakdown' => $priorityBreakdown,
            ],
            'items' => $items,
        ];
    }

    /**
     * 6. Project Progress & Health Reports
     */
    public function getProjectProgressReport(User $actor, array $filters = []): array
    {
        $today = Carbon::today()->toDateString();
        $projectQuery = $this->access->constrainProjects(Project::query(), $actor, 'reports.view')
            ->with(['department:id,name,code', 'teamLead:id,name,email', 'manager:id,name'])
            ->withCount(['members']);

        if (! empty($filters['department_id'])) {
            $projectQuery->where('projects.department_id', (int) $filters['department_id']);
        }

        if (! empty($filters['status'])) {
            $projectQuery->where('projects.status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $projectQuery->where('projects.priority', $filters['priority']);
        }

        $projects = $projectQuery->orderBy('projects.deadline')->get();

        $onTrackCount = 0;
        $dueSoonCount = 0;
        $overdueCount = 0;
        $completedCount = 0;
        $totalProgressSum = 0;

        $items = [];
        foreach ($projects as $p) {
            $tasksStats = Task::where('project_id', $p->id)
                ->selectRaw("
                    COUNT(*) as total_tasks,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_tasks,
                    COALESCE(SUM(estimated_hours), 0) as estimated_hours,
                    COALESCE(SUM(actual_hours), 0) as actual_hours
                ")
                ->first();

            $totalTasks = (int) ($tasksStats->total_tasks ?? 0);
            $completedTasks = (int) ($tasksStats->completed_tasks ?? 0);
            $progressPercent = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0.0;
            $totalProgressSum += $progressPercent;

            // Health Status
            if ($p->status === Project::STATUS_COMPLETED) {
                $health = 'completed';
                $completedCount++;
            } elseif ($p->deadline && Carbon::parse($p->deadline)->isPast()) {
                $health = 'overdue';
                $overdueCount++;
            } elseif ($p->deadline && Carbon::parse($p->deadline)->diffInDays(Carbon::today(), false) >= -7) {
                $health = 'due_soon';
                $dueSoonCount++;
            } else {
                $health = 'on_track';
                $onTrackCount++;
            }

            $daysRemaining = $p->deadline ? (int) Carbon::today()->diffInDays(Carbon::parse($p->deadline), false) : null;

            $items[] = [
                'project_id'        => $p->id,
                'name'              => $p->name,
                'code'              => $p->code,
                'status'            => $p->status,
                'priority'          => $p->priority,
                'start_date'        => $p->start_date?->toDateString(),
                'deadline'          => $p->deadline?->toDateString(),
                'days_remaining'    => $daysRemaining,
                'health'            => $health,
                'department_name'   => $p->department?->name,
                'team_lead_name'    => $p->teamLead?->name,
                'manager_name'      => $p->manager?->name,
                'members_count'     => (int) ($p->members_count ?? 0),
                'total_tasks'       => $totalTasks,
                'completed_tasks'   => $completedTasks,
                'progress_percent'  => $progressPercent,
                'estimated_hours'   => round((float) ($tasksStats->estimated_hours ?? 0), 2),
                'actual_hours'      => round((float) ($tasksStats->actual_hours ?? 0), 2),
            ];
        }

        $avgProgress = count($items) > 0 ? round($totalProgressSum / count($items), 1) : 0.0;

        return [
            'summary' => [
                'total_projects'       => count($items),
                'on_track_count'       => $onTrackCount,
                'due_soon_count'       => $dueSoonCount,
                'overdue_count'        => $overdueCount,
                'completed_count'      => $completedCount,
                'avg_progress_percent' => $avgProgress,
            ],
            'items' => $items,
        ];
    }

    /**
     * 7. Team Performance Scorecards
     */
    public function getTeamPerformanceData(User $actor, array $filters = []): array
    {
        $userQuery = $this->access->constrainUsers(User::query(), $actor, 'reports.view')
            ->where('is_active', true)
            ->with(['department:id,name', 'designation:id,name']);

        if (! empty($filters['department_id'])) {
            $userQuery->where('users.department_id', (int) $filters['department_id']);
        }

        $dateRange = $this->parseDateRange($filters);
        $users = $userQuery->orderBy('name')->get();

        $rows = [];
        foreach ($users as $u) {
            $taskQuery = Task::where('assigned_to_id', $u->id);
            if ($dateRange['start'] && $dateRange['end']) {
                $taskQuery->whereBetween('created_at', [$dateRange['start'], $dateRange['end']]);
            }

            $totalAssigned = (int) (clone $taskQuery)->count();
            $completedTasks = (clone $taskQuery)->where('status', Task::STATUS_COMPLETED)->get();
            $completedCount = $completedTasks->count();

            $onTimeCount = 0;
            $lateCount = 0;
            $totalDurationHours = 0.0;

            foreach ($completedTasks as $t) {
                if (! $t->due_date || ($t->completed_at && Carbon::parse($t->completed_at)->lte(Carbon::parse($t->due_date)->endOfDay()))) {
                    $onTimeCount++;
                } else {
                    $lateCount++;
                }

                if ($t->completed_at && $t->created_at) {
                    $totalDurationHours += Carbon::parse($t->created_at)->diffInHours(Carbon::parse($t->completed_at));
                }
            }

            $onTimeRate = $completedCount > 0 ? round(($onTimeCount / $completedCount) * 100, 1) : 0.0;
            $avgDuration = $completedCount > 0 ? round($totalDurationHours / $completedCount, 1) : 0.0;

            // Review Quality
            $userTaskIds = $completedTasks->pluck('id');
            $reviews = TaskReview::whereIn('task_id', $userTaskIds)
                ->selectRaw("
                    COUNT(CASE WHEN outcome = 'approved' AND round_number = 1 THEN 1 END) as approved_first,
                    COUNT(*) as total_reviews
                ")
                ->first();

            $approvedFirst = (int) ($reviews->approved_first ?? 0);
            $totalReviews = (int) ($reviews->total_reviews ?? 0);
            $reviewPassRate = $totalReviews > 0 ? round(($approvedFirst / $totalReviews) * 100, 1) : 100.0;

            // Logged Working Hours
            $timeQuery = TimeEntry::where('user_id', $u->id);
            if ($dateRange['start'] && $dateRange['end']) {
                $timeQuery->whereBetween('started_at', [$dateRange['start'], $dateRange['end']]);
            }
            $loggedSeconds = (int) $timeQuery->sum('duration_seconds');
            $loggedHours = round($loggedSeconds / 3600, 2);

            $rows[] = [
                'user_id'                  => $u->id,
                'name'                     => $u->name,
                'email'                    => $u->email,
                'employee_code'            => $u->employee_code,
                'department_name'          => $u->department?->name,
                'designation_name'         => $u->designation?->name,
                'assigned_tasks_count'     => $totalAssigned,
                'completed_tasks_count'    => $completedCount,
                'on_time_tasks_count'      => $onTimeCount,
                'late_tasks_count'         => $lateCount,
                'on_time_rate_percentage'  => $onTimeRate,
                'first_time_approval_rate' => $reviewPassRate,
                'total_logged_hours'       => $loggedHours,
                'avg_task_duration_hours'  => $avgDuration,
            ];
        }

        return [
            'summary' => [
                'total_members'        => count($rows),
                'total_tasks_completed'=> array_sum(array_column($rows, 'completed_tasks_count')),
                'avg_on_time_rate'     => count($rows) > 0 ? round(array_sum(array_column($rows, 'on_time_rate_percentage')) / count($rows), 1) : 0.0,
                'total_hours_logged'   => round(array_sum(array_column($rows, 'total_logged_hours')), 2),
            ],
            'items' => $rows,
        ];
    }

    /**
     * 8. Streamed CSV Export Engine
     */
    public function exportCsv(User $actor, string $reportType, array $filters = []): StreamedResponse
    {
        $filename = "capeonn_report_{$reportType}_" . Carbon::now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($actor, $reportType, $filters) {
            $handle = fopen('php://output', 'w');

            match ($reportType) {
                'workload'          => $this->writeWorkloadCsv($handle, $actor, $filters),
                'task_completion'   => $this->writeTaskCompletionCsv($handle, $actor, $filters),
                'time_variance'     => $this->writeTimeVarianceCsv($handle, $actor, $filters),
                'overdue_tasks'     => $this->writeOverdueTasksCsv($handle, $actor, $filters),
                'project_progress'  => $this->writeProjectProgressCsv($handle, $actor, $filters),
                'team_performance'  => $this->writeTeamPerformanceCsv($handle, $actor, $filters),
                default             => fputcsv($handle, ['Error', 'Unsupported report type']),
            };

            fclose($handle);
        }, 200, $headers);
    }

    private function writeWorkloadCsv($handle, User $actor, array $filters): void
    {
        fputcsv($handle, ['Employee Name', 'Email', 'Code', 'Department', 'Designation', 'Active Tasks', 'In Progress', 'In Review', 'Backlog Hours', 'Logged This Week (h)', 'Logged This Month (h)', 'Workload Status']);
        $data = $this->getEmployeeWorkload($actor, $filters);
        foreach ($data['items'] as $row) {
            fputcsv($handle, [
                $row['name'],
                $row['email'],
                $row['employee_code'],
                $row['department_name'],
                $row['designation_name'],
                $row['active_tasks_count'],
                $row['in_progress_tasks_count'],
                $row['in_review_tasks_count'],
                $row['backlog_hours'],
                $row['logged_hours_this_week'],
                $row['logged_hours_this_month'],
                ucfirst(str_replace('_', ' ', $row['workload_status'])),
            ]);
        }
    }

    private function writeTaskCompletionCsv($handle, User $actor, array $filters): void
    {
        fputcsv($handle, ['Date', 'Completed Tasks Count']);
        $data = $this->getTaskCompletionReport($actor, $filters);
        foreach ($data['velocity_timeline'] as $row) {
            fputcsv($handle, [$row['date'], $row['completed_count']]);
        }
    }

    private function writeTimeVarianceCsv($handle, User $actor, array $filters): void
    {
        fputcsv($handle, ['Task Title', 'Status', 'Priority', 'Project Code', 'Project Name', 'Assignee', 'Estimated Hours', 'Actual Hours', 'Variance (h)', 'Variance %', 'Classification']);
        $data = $this->getTimeVarianceReport($actor, $filters);
        foreach ($data['items'] as $row) {
            fputcsv($handle, [
                $row['title'],
                $row['status'],
                $row['priority'],
                $row['project_code'],
                $row['project_name'],
                $row['assignee_name'],
                $row['estimated_hours'],
                $row['actual_hours'],
                $row['variance_hours'],
                $row['variance_percentage'] . '%',
                ucfirst(str_replace('_', ' ', $row['classification'])),
            ]);
        }
    }

    private function writeOverdueTasksCsv($handle, User $actor, array $filters): void
    {
        fputcsv($handle, ['Task Title', 'Status', 'Priority', 'Project Code', 'Project Name', 'Assignee', 'Due Date', 'Days Overdue', 'Aging Bucket', 'Estimated Hours', 'Actual Hours']);
        $data = $this->getOverdueTasksReport($actor, $filters);
        foreach ($data['items'] as $row) {
            fputcsv($handle, [
                $row['title'],
                $row['status'],
                $row['priority'],
                $row['project_code'],
                $row['project_name'],
                $row['assignee_name'],
                $row['due_date'],
                $row['days_overdue'],
                $row['aging_bucket'],
                $row['estimated_hours'],
                $row['actual_hours'],
            ]);
        }
    }

    private function writeProjectProgressCsv($handle, User $actor, array $filters): void
    {
        fputcsv($handle, ['Project Code', 'Project Name', 'Status', 'Priority', 'Department', 'Team Lead', 'Deadline', 'Days Remaining', 'Health', 'Members Count', 'Total Tasks', 'Completed Tasks', 'Progress %', 'Estimated Hours', 'Actual Hours']);
        $data = $this->getProjectProgressReport($actor, $filters);
        foreach ($data['items'] as $row) {
            fputcsv($handle, [
                $row['code'],
                $row['name'],
                $row['status'],
                $row['priority'],
                $row['department_name'],
                $row['team_lead_name'],
                $row['deadline'],
                $row['days_remaining'],
                ucfirst(str_replace('_', ' ', $row['health'])),
                $row['members_count'],
                $row['total_tasks'],
                $row['completed_tasks'],
                $row['progress_percent'] . '%',
                $row['estimated_hours'],
                $row['actual_hours'],
            ]);
        }
    }

    private function writeTeamPerformanceCsv($handle, User $actor, array $filters): void
    {
        fputcsv($handle, ['Member Name', 'Email', 'Code', 'Department', 'Designation', 'Assigned Tasks', 'Completed Tasks', 'On-Time Tasks', 'Late Tasks', 'On-Time %', '1st-Time Approval %', 'Total Logged Hours', 'Avg Turnaround (h)']);
        $data = $this->getTeamPerformanceData($actor, $filters);
        foreach ($data['items'] as $row) {
            fputcsv($handle, [
                $row['name'],
                $row['email'],
                $row['employee_code'],
                $row['department_name'],
                $row['designation_name'],
                $row['assigned_tasks_count'],
                $row['completed_tasks_count'],
                $row['on_time_tasks_count'],
                $row['late_tasks_count'],
                $row['on_time_rate_percentage'] . '%',
                $row['first_time_approval_rate'] . '%',
                $row['total_logged_hours'],
                $row['avg_task_duration_hours'],
            ]);
        }
    }

    private function computeWorkloadCounts(Builder $userQuery): array
    {
        $userIds = (clone $userQuery)->where('is_active', true)->pluck('id');
        $activeTaskCounts = Task::whereIn('assigned_to_id', $userIds)
            ->where('status', '!=', Task::STATUS_COMPLETED)
            ->selectRaw('assigned_to_id, COUNT(*) as count, COALESCE(SUM(estimated_hours), 0) as hours')
            ->groupBy('assigned_to_id')
            ->get()
            ->keyBy('assigned_to_id');

        $totalMembers = count($userIds);
        $overloaded = 0;
        $nearCapacity = 0;
        $normal = 0;
        $underutilized = 0;

        foreach ($userIds as $id) {
            $rec = $activeTaskCounts->get($id);
            $cnt = $rec ? (int) $rec->count : 0;
            $hrs = $rec ? (float) $rec->hours : 0.0;

            if ($cnt > 5 || $hrs > 40) {
                $overloaded++;
            } elseif ($cnt === 5 || $hrs > 35) {
                $nearCapacity++;
            } elseif ($cnt >= 1) {
                $normal++;
            } else {
                $underutilized++;
            }
        }

        return [
            'total_members'  => $totalMembers,
            'overloaded'     => $overloaded,
            'near_capacity'  => $nearCapacity,
            'normal'         => $normal,
            'underutilized'  => $underutilized,
        ];
    }
}
