<?php

namespace App\Console\Commands;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ProjectFile;
use App\Models\ProjectFileVersion;
use App\Models\Upload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanUploadsCommand extends Command
{
    protected $signature = 'capeonn:cleanup-uploads {--dry-run : Run without deleting}';

    protected $description = 'Clean up unattached chat uploads older than 24h, and permanently purge deleted message/file attachments older than 30 days';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'Checking cleanup targets (Dry Run)...' : 'Cleaning uploads and pruned attachments...');

        $unattachedThreshold = now()->subHours(24);
        $purgeThreshold = now()->subDays(30);

        // 1. Unattached uploads older than 24 hours
        $unattachedUploads = Upload::where('created_at', '<', $unattachedThreshold)->get();
        $unattachedCount = 0;

        foreach ($unattachedUploads as $upload) {
            $isAttached = ChatAttachment::where('file_path', $upload->file_path)->exists();
            if (! $isAttached) {
                if (! $dryRun) {
                    $disk = Storage::disk($upload->disk ?: 'local');
                    if ($disk->exists($upload->file_path)) {
                        $disk->delete($upload->file_path);
                    }
                    $upload->delete();
                }
                $unattachedCount++;
            }
        }
        $this->info("Pruned unattached uploads: {$unattachedCount}");

        // 2. Permanently delete chat messages trashed > 30 days ago and their attachments
        $deletedMessages = ChatMessage::onlyTrashed()
            ->where('deleted_at', '<', $purgeThreshold)
            ->with('attachments')
            ->get();

        $prunedAttachments = 0;
        foreach ($deletedMessages as $msg) {
            foreach ($msg->attachments as $att) {
                if (! $dryRun) {
                    $disk = Storage::disk('local');
                    if ($disk->exists($att->file_path)) {
                        $disk->delete($att->file_path);
                    }
                    $att->delete();
                }
                $prunedAttachments++;
            }
            if (! $dryRun) {
                $msg->forceDelete();
            }
        }
        $this->info("Pruned old deleted message attachments: {$prunedAttachments}");

        // 3. Permanently delete soft-deleted project files trashed > 30 days ago
        $deletedFiles = ProjectFile::onlyTrashed()
            ->where('deleted_at', '<', $purgeThreshold)
            ->with('versions')
            ->get();

        $prunedProjectFiles = 0;
        foreach ($deletedFiles as $file) {
            if (! $dryRun) {
                $disk = Storage::disk('local');
                if ($disk->exists($file->file_path)) {
                    $disk->delete($file->file_path);
                }
                foreach ($file->versions as $v) {
                    if ($disk->exists($v->file_path)) {
                        $disk->delete($v->file_path);
                    }
                    $v->delete();
                }
                $file->forceDelete();
            }
            $prunedProjectFiles++;
        }
        $this->info("Pruned old deleted project files: {$prunedProjectFiles}");

        $this->info('Upload cleanup finished successfully.');
        return self::SUCCESS;
    }
}
