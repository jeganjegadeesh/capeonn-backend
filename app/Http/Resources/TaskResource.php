<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Task */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actorId = $request->user()?->id;
        $activeTimer = null;
        if ($this->relationLoaded('timeEntries')) {
            $activeTimer = $this->timeEntries->first(fn ($te) => (int) $te->user_id === (int) $actorId && $te->ended_at === null);
        } elseif ($actorId && $this->id) {
            $activeTimer = $this->activeTimerFor($actorId);
        }

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'project_id' => $this->project_id,
            'parent_task_id' => $this->parent_task_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'position' => (int) ($this->position ?? 0),
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => (bool) $this->is_overdue,
            'days_remaining' => $this->days_remaining,
            'estimated_hours' => $this->estimated_hours !== null ? (float) $this->estimated_hours : null,
            'actual_hours' => (float) ($this->actual_hours ?? 0),
            'time_variance' => $this->time_variance,
            'deadline_variance' => $this->deadline_variance,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'id' => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
                'email' => $this->assignedTo->email,
                'employee_code' => $this->assignedTo->employee_code,
                'avatar_url' => $this->assignedTo->avatar_url,
            ] : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'subtasks_count' => $this->when(
                $this->subtasks_count !== null,
                fn () => (int) $this->subtasks_count,
                fn () => $this->relationLoaded('subtasks') ? $this->subtasks->count() : 0
            ),
            'has_active_timer' => $activeTimer !== null,
            'active_timer' => $activeTimer ? [
                'id' => $activeTimer->id,
                'is_paused' => (bool) $activeTimer->is_paused,
                'paused_at' => $activeTimer->paused_at?->toIso8601String(),
                'started_at' => $activeTimer->started_at?->toIso8601String(),
                'duration_seconds' => (int) $activeTimer->started_at?->diffInSeconds(now()),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
