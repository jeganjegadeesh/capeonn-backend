<?php

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProjectAssignedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Project $project,
        public ?User $teamLead,
        public User $actor,
        public ?string $reason = null,
        public bool $keptOldLeadAsMember = false,
    ) {
    }
}
