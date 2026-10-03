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

        $hrDept = Department::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'HR'],
            ['name' => 'Human Resources'],
        );

        $designations['HR Specialist'] = Designation::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'HR Specialist'],
        );

        $hrUser = $this->user($company, 'Hannah HR', 'hr@capeonn.test', 8, Role::HR,
            $roles, $hrDept, $designations['HR Specialist'], null);
        $hrDept->update(['head_user_id' => $hrUser->id]);

        // Phase 3: Office Location (Geofencing)
        $office = \App\Models\OfficeLocation::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Capeonn HQ - Tech Park'],
            [
                'address' => '100 Innovation Boulevard, Bangalore, KA, India',
                'latitude' => 12.9716000,
                'longitude' => 77.5946000,
                'radius_meters' => 1000,
                'is_active' => true,
            ]
        );

        // Phase 3: Leave Types
        $leaveTypes = [
            ['name' => 'Casual Leave', 'code' => 'CL', 'annual_days' => 12, 'is_paid' => true],
            ['name' => 'Sick Leave', 'code' => 'SL', 'annual_days' => 10, 'is_paid' => true],
            ['name' => 'Privilege Leave', 'code' => 'PL', 'annual_days' => 15, 'is_paid' => true],
            ['name' => 'Loss of Pay', 'code' => 'LOP', 'annual_days' => 0, 'is_paid' => false],
        ];

        $createdLeaveTypes = [];
        foreach ($leaveTypes as $lt) {
            $createdLeaveTypes[$lt['code']] = \App\Models\LeaveType::firstOrCreate(
                ['company_id' => $company->id, 'code' => $lt['code']],
                [
                    'name' => $lt['name'],
                    'annual_days' => $lt['annual_days'],
                    'is_paid' => $lt['is_paid'],
                    'is_active' => true,
                ]
            );
        }

        // Initialize 2026 balances for all eligible company users (Skip Super Admin)
        $eligibleUsers = User::where('company_id', $company->id)
            ->whereHas('role', fn ($q) => $q->whereNotIn('slug', [Role::SUPER_ADMIN, 'admin']))
            ->get();
        foreach ($eligibleUsers as $u) {
            foreach ($createdLeaveTypes as $lt) {
                \App\Models\LeaveBalance::firstOrCreate(
                    ['user_id' => $u->id, 'leave_type_id' => $lt->id, 'year' => 2026],
                    [
                        'company_id' => $company->id,
                        'total_days' => $lt->annual_days,
                        'used_days' => 0,
                        'pending_days' => 0,
                    ]
                );
            }
        }

        // Phase 3: Holidays 2026
        $holidays = [
            ['name' => "New Year's Day", 'date' => '2026-01-01', 'holiday_type' => 'national'],
            ['name' => 'Republic Day', 'date' => '2026-01-26', 'holiday_type' => 'national'],
            ['name' => 'Labour Day', 'date' => '2026-05-01', 'holiday_type' => 'national'],
            ['name' => 'Independence Day', 'date' => '2026-08-15', 'holiday_type' => 'national'],
            ['name' => 'Gandhi Jayanti', 'date' => '2026-10-02', 'holiday_type' => 'national'],
            ['name' => 'Diwali / Deepavali', 'date' => '2026-11-08', 'holiday_type' => 'festival'],
            ['name' => 'Christmas', 'date' => '2026-12-25', 'holiday_type' => 'festival'],
        ];

        foreach ($holidays as $h) {
            \App\Models\Holiday::firstOrCreate(
                ['company_id' => $company->id, 'date' => $h['date']],
                [
                    'name' => $h['name'],
                    'holiday_type' => $h['holiday_type'],
                    'is_optional' => false,
                ]
            );
        }

        // Phase 4: Demo Projects
        $p1 = \App\Models\Project::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'PRJ-001'],
            [
                'department_id' => $engineering->id,
                'name' => 'Capeonn Cross-Platform Platform',
                'client_name' => 'Capeonn Internal',
                'description' => 'Cross-platform employee and project management platform with Laravel backend and Flutter frontend.',
                'status' => \App\Models\Project::STATUS_IN_PROGRESS,
                'priority' => \App\Models\Project::PRIORITY_HIGH,
                'team_lead_id' => $teamLead->id,
                'created_by_id' => $manager->id,
                'start_date' => '2026-09-01',
                'deadline' => '2026-11-30',
                'estimated_hours' => 240,
                'budget' => 35000,
                'progress' => 65,
            ]
        );
        $p1->members()->syncWithoutDetaching([
            $teamLead->id => ['project_role' => 'Team Lead', 'assigned_by_id' => $manager->id, 'assigned_at' => now()->subDays(30)],
            $roles['employee'] ? User::where('email', 'employee1@capeonn.test')->value('id') : 4 => ['project_role' => 'Flutter Developer', 'assigned_by_id' => $teamLead->id, 'assigned_at' => now()->subDays(25)],
            $roles['employee'] ? User::where('email', 'employee2@capeonn.test')->value('id') : 5 => ['project_role' => 'UI/UX Designer', 'assigned_by_id' => $teamLead->id, 'assigned_at' => now()->subDays(25)],
        ]);
        if ($p1->wasRecentlyCreated || $p1->activities()->count() === 0) {
            $p1->recordActivity('created', "Project created by {$manager->name}", $manager->id);
            $p1->recordActivity('lead_assigned', "{$teamLead->name} assigned as Team Lead by {$manager->name}", $manager->id);
            $p1->recordActivity('status_changed', "Status changed to In Progress by {$manager->name}", $manager->id);
        }

        $p2 = \App\Models\Project::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'PRJ-002'],
            [
                'department_id' => $engineering->id,
                'name' => 'FinTech Payment Gateway Integration',
                'client_name' => 'SwiftPay Ltd',
                'description' => 'High-security payment orchestration layer with tokenization and 3DS authentication.',
                'status' => \App\Models\Project::STATUS_IN_PROGRESS,
                'priority' => \App\Models\Project::PRIORITY_URGENT,
                'team_lead_id' => $teamLead->id,
                'created_by_id' => $manager->id,
                'start_date' => '2026-09-15',
                'deadline' => '2026-10-15',
                'estimated_hours' => 120,
                'budget' => 20000,
                'progress' => 80,
            ]
        );
        $p2->members()->syncWithoutDetaching([
            $teamLead->id => ['project_role' => 'Tech Lead', 'assigned_by_id' => $manager->id, 'assigned_at' => now()->subDays(20)],
            User::where('email', 'employee1@capeonn.test')->value('id') => ['project_role' => 'Backend Architect', 'assigned_by_id' => $teamLead->id, 'assigned_at' => now()->subDays(18)],
        ]);
        if ($p2->wasRecentlyCreated || $p2->activities()->count() === 0) {
            $p2->recordActivity('created', "Project created by {$manager->name}", $manager->id);
            $p2->recordActivity('lead_assigned', "{$teamLead->name} assigned as Team Lead by {$manager->name}", $manager->id);
        }

        $p3 = \App\Models\Project::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'PRJ-003'],
            [
                'department_id' => $engineering->id,
                'name' => 'Cloud Infrastructure & Microservices',
                'client_name' => 'Apex Global',
                'description' => 'Kubernetes containerization and zero-downtime CI/CD deployment pipelines.',
                'status' => \App\Models\Project::STATUS_PLANNING,
                'priority' => \App\Models\Project::PRIORITY_MEDIUM,
                'team_lead_id' => $teamLead->id,
                'created_by_id' => $manager->id,
                'start_date' => '2026-10-10',
                'deadline' => '2026-12-20',
                'estimated_hours' => 180,
                'budget' => 45000,
                'progress' => 15,
            ]
        );
        if ($p3->wasRecentlyCreated || $p3->activities()->count() === 0) {
            $p3->recordActivity('created', "Project created by {$manager->name}", $manager->id);
            $p3->recordActivity('lead_assigned', "{$teamLead->name} assigned as Team Lead by {$manager->name}", $manager->id);
        }
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
