<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'employee_code' => $this->employee_code,
            'is_active'     => $this->is_active,
            'joined_on'     => $this->joined_on?->toDateString(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),

            'company'     => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id, 'name' => $this->company->name,
                'code' => $this->company->code, 'timezone' => $this->company->timezone,
            ]),
            'department'  => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id, 'name' => $this->department->name,
            ]),
            'designation' => $this->whenLoaded('designation', fn () => [
                'id' => $this->designation->id, 'name' => $this->designation->name,
            ]),
            'role'        => $this->whenLoaded('role', fn () => [
                'id'    => $this->role->id,
                'slug'  => $this->role->slug,
                'name'  => $this->role->name,
                'level' => $this->role->level,
            ]),
            'reports_to'  => $this->whenLoaded('reportsTo', fn () => [
                'id' => $this->reportsTo->id, 'name' => $this->reportsTo->name,
            ]),

            // { "employees.view": "department", ... }  (object, so an empty map is {} not [])
            'permissions' => $this->when(
                $this->relationLoaded('role') && $this->role?->relationLoaded('permissions'),
                fn () => (object) $this->permissionScopes(),
            ),
        ];
    }
}
