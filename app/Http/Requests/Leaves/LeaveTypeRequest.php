<?php

namespace App\Http\Requests\Leaves;

use Illuminate\Foundation\Http\FormRequest;

class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:100'],
            'code'        => ['required', 'string', 'max:10'],
            'description' => ['nullable', 'string', 'max:500'],
            'annual_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'is_paid'     => ['nullable', 'boolean'],
            'is_active'   => ['nullable', 'boolean'],
        ];
    }
}
