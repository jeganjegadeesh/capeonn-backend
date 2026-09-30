<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Designation */
class DesignationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'is_active' => $this->is_active,
            'employees_count' => $this->when($this->employees_count !== null, fn () => (int) $this->employees_count),
        ];
    }
}
