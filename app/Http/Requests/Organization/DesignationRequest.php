<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Used for both creating and updating a designation. */
class DesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guarded by the permission:organization.manage route middleware
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('designations', 'name')
                    ->where('company_id', $this->user()->company_id)
                    ->ignore($this->route('designation')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
