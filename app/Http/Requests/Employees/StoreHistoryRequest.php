<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class StoreHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_type'     => ['required', 'string', 'in:joined,department_change,designation_change,role_change,reporting_change,promotion,note'],
            'title'          => ['required', 'string', 'max:150'],
            'description'    => ['nullable', 'string', 'max:1000'],
            'metadata'       => ['nullable', 'array'],
            'effective_date' => ['required', 'date'],
        ];
    }
}
