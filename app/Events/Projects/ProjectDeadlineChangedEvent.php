<?php

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProjectDeadlineChangedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Project $project,
        public ?string $oldDeadline,
        public ?string $newDeadline,
        public User $actor,
        public ?string $reason = null,
    ) {
    }
}
