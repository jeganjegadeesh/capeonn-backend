<?php

namespace App\Http\Requests\Holidays;

use Illuminate\Foundation\Http\FormRequest;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:100'],
            'date'         => ['required', 'date'],
            'holiday_type' => ['required', 'string', 'in:national,festival,company,optional'],
            'description'  => ['nullable', 'string', 'max:500'],
            'is_optional'  => ['nullable', 'boolean'],
        ];
    }
}
