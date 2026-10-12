<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Illuminate\Console\Command;

class PruneOldNotificationsCommand extends Command
{
    protected $signature = 'capeonn:prune-notifications {--days=90 : Days after which read notifications are deleted}';
    protected $description = 'Prune read in-app notifications older than specified retention period (default 90 days)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = Notification::whereNotNull('read_at')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} read notification(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
