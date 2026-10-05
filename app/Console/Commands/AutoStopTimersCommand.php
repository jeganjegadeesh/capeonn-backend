<?php

namespace App\Console\Commands;

use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoStopTimersCommand extends Command
{
    protected $signature = 'capeonn:auto-stop-timers {--max-hours=12 : Maximum allowed running hours before auto-stop}';
    protected $description = 'Automatically stop forgotten running timers that exceed the maximum continuous duration';

    public function handle(): int
    {
        $maxHours = (float) $this->option('max-hours');
        if ($maxHours <= 0) {
            $maxHours = 12.0;
        }

        $cutoff = now()->subMinutes((int) ($maxHours * 60));

        $runningEntries = TimeEntry::with(['task.project', 'user'])
            ->running()
            ->where('started_at', '<=', $cutoff)
            ->get();

        $stoppedCount = 0;

        foreach ($runningEntries as $entry) {
            $cappedEndTime = Carbon::parse($entry->started_at)->addMinutes((int) ($maxHours * 60));

            $entry->stop($cappedEndTime, isAutoStopped: true);

            if ($entry->task && $entry->task->project) {
                $entry->task->project->recordActivity(
                    action: 'timer_auto_stopped',
                    description: "Running timer for {$entry->user?->name} on task '{$entry->task->title}' was automatically stopped after {$maxHours}h limit.",
                    userId: $entry->user_id,
                    taskId: $entry->task_id,
                );
            }

            $stoppedCount++;
        }

        $this->info("Auto-stop completed. Stopped {$stoppedCount} running timer(s).");

        return self::SUCCESS;
    }
}
