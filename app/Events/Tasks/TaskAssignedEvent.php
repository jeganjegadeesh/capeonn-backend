<?php

namespace App\Events\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskAssignedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Task $task,
        public ?User $assignee,
        public User $actor,
        public ?User $previousAssignee = null,
        public ?string $reason = null,
    ) {
    }
}
