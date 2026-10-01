<?php

namespace App\Http\Requests\Leaves;

use Illuminate\Foundation\Http\FormRequest;

class LeaveApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'leave_type_id'   => ['required', 'integer', 'exists:leave_types,id'],
            'start_date'      => ['required', 'date'],
            'end_date'        => ['required', 'date', 'after_or_equal:start_date'],
            'is_half_day'     => ['nullable', 'boolean'],
            'half_day_type'   => ['nullable', 'string', 'in:first_half,second_half'],
            'reason'          => ['required', 'string', 'max:500'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
