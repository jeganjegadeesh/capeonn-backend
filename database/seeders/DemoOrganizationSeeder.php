<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY (see DatabaseSeeder).
 * One department with a full Manager -> Team Lead -> Employee chain to test against.
 * All demo users share the password: Password@123
 */
class DemoOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', config('capeonn.company.code'))->firstOrFail();
        $roles = Role::pluck('id', 'slug');

        $engineering = Department::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ENG'],
            ['name' => 'Engineering'],
        );

        $designations = [];
        foreach (['Engineering Manager', 'Team Lead', 'Software Engineer'] as $name) {
            $designations[$name] = Designation::firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
            );
        }

        $manager = $this->user($company, 'Meera Manager', 'manager@capeonn.test', 2, Role::MANAGER,
            $roles, $engineering, $designations['Engineering Manager'], null);

        $teamLead = $this->user($company, 'Tara Lead', 'tl@capeonn.test', 3, Role::TEAM_LEAD,
            $roles, $engineering, $designations['Team Lead'], $manager);

        $this->user($company, 'Eli Employee', 'employee1@capeonn.test', 4, Role::EMPLOYEE,
            $roles, $engineering, $designations['Software Engineer'], $teamLead);

        $this->user($company, 'Esha Employee', 'employee2@capeonn.test', 5, Role::EMPLOYEE,
            $roles, $engineering, $designations['Software Engineer'], $teamLead);

        $engineering->update(['head_user_id' => $manager->id]);
    }

    private function user(Company $company, string $name, string $email, int $number, string $roleSlug,
        $roles, Department $department, Designation $designation, ?User $reportsTo): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name'           => $name,
                'password'       => 'Password@123',
                'company_id'     => $company->id,
                'department_id'  => $department->id,
                'designation_id' => $designation->id,
                'role_id'        => $roles[$roleSlug],
                'reports_to_id'  => $reportsTo?->id,
                'employee_code'  => sprintf('%s-%04d', $company->code, $number),
                'joined_on'      => now()->toDateString(),
                'is_active'      => true,
            ],
        );
    }
}
