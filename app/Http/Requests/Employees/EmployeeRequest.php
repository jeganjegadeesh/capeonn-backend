<?php

namespace App\Http\Requests\Employees;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/** Create (POST) and update (PUT) an employee. */
class EmployeeRequest extends FormRequest
{
    private ?User $target = null;

    public function isCreating(): bool
    {
        return $this->isMethod('POST');
    }

    /** The employee being edited (null when creating). 404 if not in the actor's company. */
    public function target(): ?User
    {
        if ($this->isCreating()) {
            return null;
        }

        return $this->target ??= User::where('company_id', $this->user()->company_id)
            ->with('role')
            ->findOrFail((int) $this->route('employee'));
    }

    public function authorize(): bool
    {
        if ($this->isCreating()) {
            return true; // permission:employees.manage middleware already ran
        }

        return app(AccessControl::class)->canManageUser($this->user(), $this->target());
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('email'))) {
            $merge['email'] = Str::lower(trim($this->input('email')));
        }

        // Managers can only work inside their own department, so default to it.
        if ($this->isCreating()
            && $this->input('department_id') === null
            && $this->user()->scopeFor('employees.manage') === Permission::SCOPE_DEPARTMENT) {
            $merge['department_id'] = $this->user()->department_id;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;
        $creating  = $this->isCreating();
        $required  = $creating ? 'required' : 'sometimes';
        $targetId  = $this->target()?->id;
        $assignable = app(AccessControl::class)->assignableRoles($this->user())->pluck('id')->all();

        return [
            'name'  => [$required, 'string', 'max:255'],
            'email' => [$required, 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($targetId)],
            // Initial password is set by the admin/manager. Users change it themselves afterwards.
            'password' => $creating ? ['required', 'string', Password::defaults()] : ['prohibited'],
            'phone'    => ['nullable', 'string', 'max:30'],
            'employee_code' => [
                'nullable', 'string', 'max:30',
                Rule::unique('users', 'employee_code')->where('company_id', $companyId)->ignore($targetId),
            ],
            'department_id' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id')->where(fn ($q) => $q
                    ->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')),
            ],
            'designation_id' => [
                'nullable', 'integer',
                Rule::exists('designations', 'id')->where(fn ($q) => $q
                    ->where('company_id', $companyId)->where('is_active', true)),
            ],
            'role_id' => [$required, 'integer', Rule::in($assignable)],
            'reports_to_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q
                    ->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')),
            ],
            'joined_on' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'role_id.in'        => 'You are not allowed to assign that role.',
            'email.unique'      => 'This email address is already in use.',
            'department_id.exists'  => 'Choose an active department from your company.',
            'designation_id.exists' => 'Choose an active designation from your company.',
            'reports_to_id.exists'  => 'Choose an active person from your company to report to.',
        ];
    }

    /** Rules that depend on several fields, or on who is asking. Runs only if the basic rules passed. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $actor  = $this->user();
            $target = $this->target();

            // The values this person will have once the request is applied.
            $roleId       = $this->has('role_id') ? (int) $this->input('role_id') : $target?->role_id;
            $reportsToId  = $this->has('reports_to_id') ? $this->input('reports_to_id') : $target?->reports_to_id;
            $departmentId = $this->has('department_id') ? $this->input('department_id') : $target?->department_id;
            $roleLevel    = (int) Role::whereKey($roleId)->value('level');

            // 1. Department-scoped actors (Managers) stay inside their own department.
            if ($actor->scopeFor('employees.manage') === Permission::SCOPE_DEPARTMENT
                && ($actor->department_id === null || (int) $departmentId !== (int) $actor->department_id)) {
                $validator->errors()->add('department_id', 'You can only manage employees in your own department.');
            }

            // 2. Reporting line: you report to someone with a higher role (Employee -> TL -> Manager -> Admin).
            if ($reportsToId !== null) {
                $supervisor = User::with('role')->find($reportsToId);
                $supervisorLevel = (int) ($supervisor?->role?->level ?? 0);

                if ($supervisorLevel <= $roleLevel) {
                    $validator->errors()->add('reports_to_id', 'A person must report to someone with a higher role.');
                } elseif ($actor->scopeFor('employees.manage') === Permission::SCOPE_DEPARTMENT
                    && (int) $supervisor->department_id !== (int) $actor->department_id) {
                    $validator->errors()->add('reports_to_id', 'Choose someone from your own department.');
                }
            }

            if ($target) {
                // 3. Changing the role must not leave existing direct reports outranking this person.
                if ($this->has('role_id') && User::where('reports_to_id', $target->id)
                        ->whereHas('role', fn ($q) => $q->where('level', '>=', $roleLevel))->exists()) {
                    $validator->errors()->add('role_id', 'Some of this person\'s direct reports would outrank them. Reassign those people first.');
                }

                // 4. Nobody can lock themselves out.
                if ($target->id === $actor->id) {
                    if ($this->has('role_id') && (int) $this->input('role_id') !== (int) $target->role_id) {
                        $validator->errors()->add('role_id', 'You cannot change your own role.');
                    }
                    if ($this->has('is_active') && ! $this->boolean('is_active')) {
                        $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
                    }
                }
            }
        }];
    }
}
