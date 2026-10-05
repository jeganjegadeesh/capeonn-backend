<?php

namespace App\Events\Projects;

use App\Models\ProjectFile;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProjectFileUploadedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ProjectFile $file,
        public User $uploader
    ) {
    }
}
