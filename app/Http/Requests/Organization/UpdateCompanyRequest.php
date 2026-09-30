<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guarded by the permission:organization.manage route middleware
    }

    /** The company code and active flag are not editable through this endpoint. */
    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email'      => ['nullable', 'email', 'max:255'],
            'phone'      => ['nullable', 'string', 'max:30'],
            'address'    => ['nullable', 'string', 'max:1000'],
            'timezone'   => ['required', 'timezone'],
        ];
    }
}
