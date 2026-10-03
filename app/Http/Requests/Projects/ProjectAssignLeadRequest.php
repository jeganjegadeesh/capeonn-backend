<?php

namespace App\Http\Requests\Projects;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectAssignLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'team_lead_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(function ($query) use ($companyId) {
                    $query->where('company_id', $companyId)
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                        ->whereIn('role_id', function ($sub) {
                            $sub->select('id')->from('roles')->where('slug', Role::TEAM_LEAD);
                        });
                }),
            ],
            'reason' => ['nullable', 'string', 'max:500'],
            'keep_as_member' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'team_lead_id.exists' => 'The selected Team Lead must be an active employee with the Team Lead role in your company.',
        ];
    }
}
