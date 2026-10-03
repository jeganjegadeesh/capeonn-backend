<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pivot = $this->pivot;

        return [
            'id' => $pivot?->id ?? $this->id,
            'user_id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'employee_code' => $this->employee_code,
            'project_role' => $pivot?->project_role ?? 'Member',
            'assigned_at' => $pivot?->assigned_at ? \Carbon\Carbon::parse($pivot->assigned_at)->toIso8601String() : null,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'designation' => $this->whenLoaded('designation', fn () => $this->designation ? [
                'id' => $this->designation->id,
                'name' => $this->designation->name,
            ] : null),
            'role' => $this->whenLoaded('role', fn () => $this->role ? [
                'id' => $this->role->id,
                'slug' => $this->role->slug,
                'name' => $this->role->name,
            ] : null),
        ];
    }
}
