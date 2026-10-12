<?php

namespace App\Console\Commands;

use App\Events\Projects\ProjectDeadlineApproachingEvent;
use App\Events\Projects\ProjectOverdueEvent;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckProjectDeadlinesCommand extends Command
{
    protected $signature = 'capeonn:check-project-deadlines';
    protected $description = 'Scan active projects and fire events for approaching deadlines (7 days, 1 day) and overdue projects';

    public function handle(): int
    {
        $today = Carbon::today('Asia/Kolkata');

        $projects = Project::whereNotIn('status', [
            Project::STATUS_COMPLETED,
            Project::STATUS_ARCHIVED,
            Project::STATUS_CANCELLED,
        ])
        ->whereNotNull('deadline')
        ->get();

        $approachingCount = 0;
        $overdueCount = 0;

        $todayStr = Carbon::today('Asia/Kolkata')->format('Y-m-d');
        $todayDate = Carbon::parse($todayStr);

        foreach ($projects as $project) {
            $deadlineStr = Carbon::parse($project->deadline)->format('Y-m-d');
            $daysDiff = (int) $todayDate->diffInDays(Carbon::parse($deadlineStr), false);

            if ($daysDiff < 0) {
                // Overdue
                ProjectOverdueEvent::dispatch($project, abs($daysDiff));
                $overdueCount++;
            } elseif ($daysDiff === 7 || $daysDiff === 1) {
                // Approaching deadline
                ProjectDeadlineApproachingEvent::dispatch($project, $daysDiff);
                $approachingCount++;
            }
        }

        $this->info("Deadline check completed. Approaching: {$approachingCount}, Overdue: {$overdueCount}.");

        return self::SUCCESS;
    }
}
