<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class RegularizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date'                => ['required', 'date', 'before_or_equal:today'],
            'requested_clock_in'  => ['required', 'date'],
            'requested_clock_out' => ['required', 'date', 'after:requested_clock_in'],
            'reason'              => ['required', 'string', 'max:500'],
        ];
    }
}
