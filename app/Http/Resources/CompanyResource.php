<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'code'        => $this->code,
            'legal_name'  => $this->legal_name,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'address'     => $this->address,
            'logo_path'   => $this->logo_path,
            'timezone'    => $this->timezone,
            'is_active'   => $this->is_active,
            'departments_count' => $this->when($this->departments_count !== null, fn () => (int) $this->departments_count),
            'employees_count'   => $this->when($this->employees_count !== null, fn () => (int) $this->employees_count),
        ];
    }
}
