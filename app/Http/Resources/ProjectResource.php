<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'client_name' => $this->client_name,
            'status' => $this->status,
            'accepts_work' => (bool) $this->accepts_work,
            'is_archived' => (bool) $this->is_archived,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'status_change_reason' => $this->status_change_reason,
            'completion_requested_at' => $this->completion_requested_at?->toIso8601String(),
            'completion_request_notes' => $this->completion_request_notes,
            'priority' => $this->priority,
            'progress' => $this->progress,
            'task_metrics_available' => $this->progress !== null,
            'task_metrics' => $this->task_metrics,
            'health_label' => $this->health_label,
            'start_date' => $this->start_date?->toDateString(),
            'deadline' => $this->deadline?->toDateString(),
            'estimated_hours' => $this->estimated_hours !== null ? (float) $this->estimated_hours : null,
            'budget' => $this->budget !== null ? (float) $this->budget : null,
            'is_overdue' => (bool) $this->is_overdue,
            'days_remaining' => $this->days_remaining,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null),
            'manager_id' => $this->manager_id,
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id,
                'name' => $this->manager->name,
            ] : null),
            'team_lead' => $this->whenLoaded('teamLead', fn () => $this->teamLead ? [
                'id' => $this->teamLead->id,
                'name' => $this->teamLead->name,
                'email' => $this->teamLead->email,
                'employee_code' => $this->teamLead->employee_code,
            ] : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'members_count' => $this->when(
                $this->members_count !== null,
                fn () => (int) $this->members_count,
                fn () => $this->relationLoaded('members') ? $this->members->count() : null
            ),
            'total_team_count' => $this->total_team_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
