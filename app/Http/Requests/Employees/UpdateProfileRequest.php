<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dob'                     => ['nullable', 'date'],
            'gender'                  => ['nullable', 'string', 'in:male,female,other,prefer_not_to_say'],
            'address'                 => ['nullable', 'string', 'max:1000'],
            'emergency_contact_name'  => ['nullable', 'string', 'max:100'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'employment_type'         => ['nullable', 'string', 'in:full_time,part_time,contract,intern'],
            'probation_end_date'      => ['nullable', 'date'],
            'salary'                  => ['nullable', 'numeric', 'min:0'],
            'joined_on'               => ['nullable', 'date'],
            'designation_id'          => ['nullable', 'integer'],
            'skills'                  => ['nullable', 'array'],
            'skills.*'                => ['string', 'max:50'],
            'certifications'          => ['nullable', 'array'],
            'certifications.*.name'   => ['required_with:certifications', 'string', 'max:150'],
            'certifications.*.issuer' => ['nullable', 'string', 'max:150'],
            'certifications.*.date'   => ['nullable', 'string', 'max:50'],
        ];
    }
}
