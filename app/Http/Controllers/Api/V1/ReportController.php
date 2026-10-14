<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports)
    {
    }

    /**
     * GET /api/v1/reports/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $actor = $request->user();
        $filters = $request->only(['department_id', 'project_id', 'preset', 'start_date', 'end_date']);
        $data = $this->reports->getDashboardSummary($actor, $filters);

        return $this->success($data, 'Dashboard analytics retrieved successfully');
    }

    /**
     * GET /api/v1/reports/workload
     */
    public function workload(Request $request): JsonResponse
    {
        $actor = $request->user();
        $filters = $request->only(['department_id', 'search']);
        $data = $this->reports->getEmployeeWorkload($actor, $filters);

        return $this->success($data, 'Employee workload report retrieved successfully');
    }

    /**
     * GET /api/v1/reports/task-completion
     */
    public function taskCompletion(Request $request): JsonResponse
    {
        $actor = $request->user();
        $filters = $request->only(['department_id', 'project_id', 'assigned_to_id', 'preset', 'start_date', 'end_date']);
        $data = $this->reports->getTaskCompletionReport($actor, $filters);

        return $this->success($data, 'Task completion report retrieved successfully');
    }

    /**
     * GET /api/v1/reports/time-variance
     */
    public function timeVariance(Request $request): JsonResponse
    {
        $actor = $request->user();
        $filters = $request->only(['department_id', 'project_id', 'assigned_to_id', 'classification', 'preset', 'start_date', 'end_date']);
        $data = $this->reports->getTimeVarianceReport($actor, $filters);

        return $this->success($data, 'Estimated vs actual time variance report retrieved successfully');
    }

    /**
     * GET /api/v1/reports/overdue-tasks
     */
    public function overdueTasks(Request $request): JsonResponse
    {
        $actor = $request->user();
        $filters = $request->only(['department_id', 'project_id', 'priority', 'assigned_to_id']);
        $data = $this->reports->getOverdueTasksReport($actor, $filters);

        return $this->success($data, 'Overdue task report retrieved successfully');
    }

    /**
     * GET /api/v1/reports/project-progress
     */
    public function projectProgress(Request $request): JsonResponse
    {
        $actor = $request->user();
        $filters = $request->only(['department_id', 'status', 'priority']);
        $data = $this->reports->getProjectProgressReport($actor, $filters);

        return $this->success($data, 'Project progress report retrieved successfully');
    }

    /**
     * GET /api/v1/reports/team-performance
     */
    public function teamPerformance(Request $request): JsonResponse
    {
        $actor = $request->user();
        $filters = $request->only(['department_id', 'preset', 'start_date', 'end_date']);
        $data = $this->reports->getTeamPerformanceData($actor, $filters);

        return $this->success($data, 'Team performance data retrieved successfully');
    }

    /**
     * GET /api/v1/reports/export
     */
    public function export(Request $request): StreamedResponse
    {
        $actor = $request->user();
        $type = (string) $request->query('type', 'workload');
        $filters = $request->all();

        return $this->reports->exportCsv($actor, $type, $filters);
    }
}
