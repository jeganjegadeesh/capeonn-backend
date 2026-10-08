<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class MigratePublicFilesCommand extends Command
{
    protected $signature = 'capeonn:migrate-public-files {--dry-run : Run without moving files}';

    protected $description = 'Migrate legacy project and chat files from public storage disk to private local disk';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'Checking files for migration (Dry Run)...' : 'Migrating files from public to private disk...');

        $publicDisk = Storage::disk('public');
        $localDisk = Storage::disk('local');

        $directories = ['projects', 'chat_uploads', 'attachments'];
        $migratedCount = 0;

        foreach ($directories as $dir) {
            $files = $publicDisk->allFiles($dir);
            foreach ($files as $filePath) {
                $this->line("Found public file: {$filePath}");

                if (! $dryRun) {
                    // Copy to private disk if not already there
                    if (! $localDisk->exists($filePath)) {
                        $stream = $publicDisk->readStream($filePath);
                        if ($stream) {
                            $localDisk->writeStream($filePath, $stream);
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        }
                    }

                    // Delete from public disk so it's no longer publicly reachable via web server /storage/...
                    $publicDisk->delete($filePath);
                    $this->info("Moved {$filePath} to private disk and removed from public storage.");
                }

                $migratedCount++;
            }
        }

        $this->info("Migration completed. Total files processed: {$migratedCount}.");
        return self::SUCCESS;
    }
}
