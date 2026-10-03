<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
        if ($this->input('status') === 'planning') {
            $this->merge(['status' => Project::STATUS_PLANNED]);
        } elseif ($this->input('status') === 'in_progress') {
            $this->merge(['status' => Project::STATUS_ACTIVE]);
        }
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;
        $id = $this->route('project');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:30', 'alpha_dash',
                Rule::unique('projects', 'code')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($id),
            ],
            'department_id' => [
                'required', 'integer',
                Rule::exists('departments', 'id')->where(function ($query) use ($companyId) {
                    $query->where('company_id', $companyId)->whereNull('deleted_at');
                }),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'status' => [
                'sometimes', 'string',
                Rule::in([
                    Project::STATUS_PLANNED,
                    Project::STATUS_ACTIVE,
                ]),
            ],
            'priority' => [
                'sometimes', 'string',
                Rule::in([
                    Project::PRIORITY_LOW,
                    Project::PRIORITY_MEDIUM,
                    Project::PRIORITY_HIGH,
                    Project::PRIORITY_URGENT,
                ]),
            ],
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
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date', 'after_or_equal:start_date'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'members' => ['nullable', 'array'],
            'members.*.user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where(function ($query) use ($companyId) {
                    $query->where('company_id', $companyId)
                        ->where('is_active', true)
                        ->whereNull('deleted_at');
                }),
            ],
            'members.*.project_role' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This project code is already in use.',
            'department_id.exists' => 'The selected department does not exist in your company.',
            'team_lead_id.exists' => 'The selected Team Lead must be an active employee with the Team Lead role in your company.',
            'deadline.after_or_equal' => 'The deadline must be on or after the start date.',
            'members.*.user_id.exists' => 'Each assigned member must be an active employee in your company.',
        ];
    }
}
