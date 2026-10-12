<?php

namespace App\Console\Commands;

use App\Events\Tasks\TaskDueSoonEvent;
use App\Events\Tasks\TaskOverdueEvent;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckTaskDeadlinesCommand extends Command
{
    protected $signature = 'capeonn:check-task-deadlines';
    protected $description = 'Scan active tasks and fire events for approaching due dates and overdue tasks';

    public function handle(): int
    {
        $today = Carbon::today('Asia/Kolkata');

        $tasks = Task::with('project')
            ->where('status', '!=', Task::STATUS_COMPLETED)
            ->whereNotNull('due_date')
            ->whereHas('project', function ($q) {
                $q->whereNotIn('status', [
                    Project::STATUS_COMPLETED,
                    Project::STATUS_ARCHIVED,
                    Project::STATUS_CANCELLED,
                ]);
            })
            ->get();

        $dueSoonCount = 0;
        $overdueCount = 0;

        $todayStr = Carbon::today('Asia/Kolkata')->format('Y-m-d');
        $todayDate = Carbon::parse($todayStr);

        foreach ($tasks as $task) {
            $dueStr = Carbon::parse($task->due_date)->format('Y-m-d');
            $daysDiff = (int) $todayDate->diffInDays(Carbon::parse($dueStr), false);

            if ($daysDiff < 0) {
                TaskOverdueEvent::dispatch($task, abs($daysDiff));
                $overdueCount++;
            } elseif ($daysDiff <= 1 && $daysDiff >= 0) {
                TaskDueSoonEvent::dispatch($task, $daysDiff);
                $dueSoonCount++;
            }
        }

        $this->info("Task deadlines check completed. Due soon: {$dueSoonCount}, Overdue: {$overdueCount}.");

        return self::SUCCESS;
    }
}
