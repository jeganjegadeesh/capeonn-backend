<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Department */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'code'        => $this->code,
            'description' => $this->description,
            'is_active'   => $this->is_active,
            'head'        => $this->whenLoaded('head', fn () => [
                'id' => $this->head->id, 'name' => $this->head->name,
            ]),
            'employees_count' => $this->when($this->employees_count !== null, fn () => (int) $this->employees_count),
        ];
    }
}
