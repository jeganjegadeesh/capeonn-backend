<?php

namespace App\Http\Requests\Organization;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Used for both creating and updating a department. */
class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guarded by the permission:organization.manage route middleware
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;
        $id        = $this->route('department'); // null when creating

        // A department head must be an active Manager or Admin of this company.
        $headRoleIds = Role::whereIn('slug', [Role::MANAGER, Role::ADMIN])->pluck('id')->all();

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('departments', 'name')
                    ->where('company_id', $companyId)->whereNull('deleted_at')->ignore($id),
            ],
            // Deleted departments keep their code, so the code stays reserved (unique index).
            'code' => [
                'required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('departments', 'code')->where('company_id', $companyId)->ignore($id),
            ],
            'description'  => ['nullable', 'string', 'max:1000'],
            'head_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(function ($query) use ($companyId, $headRoleIds) {
                    $query->where('company_id', $companyId)
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                        ->whereIn('role_id', $headRoleIds);
                }),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique'         => 'This code is already used by another department (possibly a deleted one).',
            'head_user_id.exists' => 'The department head must be an active Manager or Admin of your company.',
        ];
    }
}
