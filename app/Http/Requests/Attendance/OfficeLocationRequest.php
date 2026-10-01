<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class OfficeLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:100'],
            'address'       => ['nullable', 'string', 'max:255'],
            'latitude'      => ['required', 'numeric', 'between:-90,90'],
            'longitude'     => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'integer', 'min:50', 'max:50000'],
            'is_active'     => ['nullable', 'boolean'],
        ];
    }
}
