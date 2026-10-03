<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TimeEntry */
class TimeEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'project_id' => $this->project_id,
            'project_name' => $this->whenLoaded('project', fn () => $this->project?->name),
            'task_id' => $this->task_id,
            'task_title' => $this->whenLoaded('task', fn () => $this->task?->title),
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'employee_code' => $this->user->employee_code,
                'avatar_url' => $this->user->avatar_url,
            ] : null),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'duration_hours' => $this->duration_hours,
            'description' => $this->description,
            'is_manual' => (bool) $this->is_manual,
            'is_running' => (bool) $this->is_running,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
