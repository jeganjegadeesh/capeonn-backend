<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Scheduled Jobs (Phase 5 Review)
 * 
 * cPanel Cron Configuration:
 * Add the following single cron entry in cPanel Cron Jobs:
 * * * * * * cd /home/<user>/public_html/capeonn && php artisan schedule:run >> /dev/null 2>&1
 */

// Daily check for project deadlines (approaching in 7d/1d and overdue)
Schedule::command('capeonn:check-project-deadlines')->dailyAt('08:00');

// Hourly check for task deadlines (due today/tomorrow and overdue)
Schedule::command('capeonn:check-task-deadlines')->hourly();

// Auto-stop forgotten continuous timers exceeding 12 hours
Schedule::command('capeonn:auto-stop-timers --max-hours=12')->everyFifteenMinutes();

// Daily cleanup of unattached uploads and purged deleted chat attachments
Schedule::command('capeonn:cleanup-uploads')->dailyAt('03:00');

