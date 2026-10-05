<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Encodes the Role & Permission Matrix (Project Scope, section 18).
 * Safe to run repeatedly: it upserts roles/permissions and re-syncs grants.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $roles = $this->seedRoles();
            $permissions = $this->seedPermissions();
            $this->seedGrants($roles, $permissions);
        });
    }

    /** @return array<string, Role> keyed by slug */
    private function seedRoles(): array
    {
        $definitions = [
            Role::SUPER_ADMIN => ['Super Admin', 100, 'Full access to the whole company and system settings'],
            'admin'           => ['Admin', 100, 'Full access to the whole company and system settings'],
            Role::HR          => ['HR', 80, 'Manages employee profiles, documents, attendance, and HR operations'],
            Role::MANAGER     => ['Manager', 70, 'Manages a department and assigns projects to Team Leads'],
            Role::TEAM_LEAD   => ['Team Lead', 50, 'Splits projects into tasks and assigns them to team members'],
            Role::EMPLOYEE    => ['Employee', 10, 'Works on assigned tasks'],
        ];

        $roles = [];
        foreach ($definitions as $slug => [$name, $level, $description]) {
            $roles[$slug] = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'level' => $level, 'description' => $description, 'is_system' => true],
            );
        }

        return $roles;
    }

    /** @return array<string, Permission> keyed by slug */
    private function seedPermissions(): array
    {
        // slug => description   (module is the part before the dot)
        $definitions = [
            'organization.view'   => 'View company and departments',
            'organization.manage' => 'Create/edit company, departments and designations',
            'roles.manage'        => 'Manage roles and permissions (Super Admin only)',
            'system.manage'       => 'Manage system settings and audit logs (Super Admin only)',
            'employees.view'      => 'View employee records',
            'employees.manage'    => 'Create/edit/deactivate employees',
            'projects.view'       => 'View projects',
            'projects.manage'     => 'Create/edit projects',
            'projects.assign'     => 'Assign projects to Team Leads',
            'projects.team'       => 'Manage project team members (Team Lead own project, Manager own dept)',
            'projects.activity'   => 'View project audit activity history',
            'projects.status'     => 'Transition project status or request completion',
            'tasks.view'          => 'View tasks',
            'tasks.manage'        => 'Create, assign and manage tasks',
            'tasks.update'        => 'Update status/progress of tasks',
            'time.view'           => 'View time tracking records',
            'time.track'          => 'Start/pause/complete own task timer',
            'chat.use'            => 'Use internal chat',
            'reports.view'        => 'View reports and dashboards',
            'attendance.view'     => 'View attendance records',
            'attendance.record'   => 'Check in / check out',
            'attendance.manage'   => 'Manage attendance, regularizations and locations',
            'leave.view'          => 'View leave requests and balances',
            'leave.apply'         => 'Apply for leave',
            'leave.approve'       => 'Approve or reject leave',
            'leave.manage'        => 'Manage leave types and allocations',
            'holidays.view'       => 'View company holidays',
            'holidays.manage'     => 'Create and manage company holidays',
            'documents.view'      => 'View employee documents',
            'documents.manage'    => 'Upload and manage employee documents',
            'documents.verify'    => 'Verify and approve employee documents',
        ];

        $permissions = [];
        foreach ($definitions as $slug => $description) {
            $permissions[$slug] = Permission::updateOrCreate(
                ['slug' => $slug],
                ['module' => explode('.', $slug)[0], 'description' => $description],
            );
        }

        return $permissions;
    }

    /** @param array<string, Role> $roles @param array<string, Permission> $permissions */
    private function seedGrants(array $roles, array $permissions): void
    {
        $sa  = Role::SUPER_ADMIN;
        $adm = 'admin';
        $hr  = Role::HR;
        $mgr = Role::MANAGER;
        $tl  = Role::TEAM_LEAD;
        $emp = Role::EMPLOYEE;

        $all  = Permission::SCOPE_ALL;
        $dept = Permission::SCOPE_DEPARTMENT;
        $team = Permission::SCOPE_TEAM;
        $asg  = Permission::SCOPE_ASSIGNED;
        $self = Permission::SCOPE_SELF;

        // permission => [role => scope]
        $matrix = [
            'organization.view'   => [$sa => $all, $adm => $all, $hr => $all, $mgr => $all],
            // Role & permission management and system settings to Super Admin only (removed from HR)
            'organization.manage' => [$sa => $all, $adm => $all],
            'roles.manage'        => [$sa => $all, $adm => $all],
            'system.manage'       => [$sa => $all, $adm => $all],

            'employees.view'      => [$sa => $all, $adm => $all, $hr => $all, $mgr => $dept, $tl => $team, $emp => $self],
            'employees.manage'    => [$sa => $all, $adm => $all, $hr => $all, $mgr => $dept],

            'projects.view'       => [$sa => $all, $adm => $all, $hr => $asg, $mgr => $dept, $tl => $asg, $emp => $asg],
            'projects.manage'     => [$sa => $all, $adm => $all, $mgr => $dept],
            'projects.assign'     => [$sa => $all, $adm => $all, $mgr => $dept],
            'projects.team'       => [$sa => $all, $adm => $all, $mgr => $dept, $tl => $asg],
            'projects.activity'   => [$sa => $all, $adm => $all, $mgr => $dept, $tl => $asg],
            'projects.status'     => [$sa => $all, $adm => $all, $mgr => $dept, $tl => $asg],

            'tasks.view'          => [$sa => $all, $adm => $all, $hr => $asg, $mgr => $dept, $tl => $team, $emp => $self],
            'tasks.manage'        => [$sa => $all, $adm => $all, $mgr => $dept, $tl => $team],
            'tasks.update'        => [$sa => $all, $adm => $all, $mgr => $dept, $tl => $team, $emp => $asg],

            'time.view'           => [$sa => $all, $adm => $all, $hr => $self, $mgr => $team, $tl => $team, $emp => $self],
            'time.track'          => [$sa => $all, $adm => $all, $mgr => $self, $hr => $self, $tl => $self, $emp => $self],

            'chat.use'            => [$sa => $all, $adm => $all, $hr => $all, $mgr => $all, $tl => $all, $emp => $all],

            'reports.view'        => [$sa => $all, $adm => $all, $hr => $all, $mgr => $dept, $tl => $team],

            'attendance.view'     => [$sa => $all, $adm => $all, $hr => $all, $mgr => $dept, $tl => $team, $emp => $self],
            // Super Admin does not record attendance
            'attendance.record'   => [$hr => $self, $mgr => $self, $tl => $self, $emp => $self],
            'attendance.manage'   => [$sa => $all, $adm => $all, $hr => $all, $mgr => $dept],

            'leave.view'          => [$sa => $all, $adm => $all, $hr => $all, $mgr => $dept, $tl => $team, $emp => $self],
            // Super Admin does not apply for leave
            'leave.apply'         => [$hr => $self, $mgr => $self, $tl => $self, $emp => $self],
            'leave.approve'       => [$sa => $all, $adm => $all, $hr => $all, $mgr => $dept, $tl => $team],
            'leave.manage'        => [$sa => $all, $adm => $all, $hr => $all],

            // Holidays: Allow add/edit/delete for super_admin and hr only. Everyone else gets read-only.
            'holidays.view'       => [$sa => $all, $adm => $all, $hr => $all, $mgr => $all, $tl => $all, $emp => $all],
            'holidays.manage'     => [$sa => $all, $adm => $all, $hr => $all],

            // Documents: HR edit all profiles, verify documents
            'documents.view'      => [$sa => $all, $adm => $all, $hr => $all, $mgr => $dept, $tl => $team, $emp => $self],
            'documents.manage'    => [$sa => $all, $adm => $all, $hr => $all, $mgr => $dept, $emp => $self],
            'documents.verify'    => [$sa => $all, $adm => $all, $hr => $all],
        ];

        $grants = array_fill_keys(array_keys($roles), []);
        foreach ($matrix as $permissionSlug => $byRole) {
            foreach ($byRole as $roleSlug => $scope) {
                $grants[$roleSlug][$permissions[$permissionSlug]->id] = ['scope' => $scope];
            }
        }

        foreach ($roles as $slug => $role) {
            $role->permissions()->sync($grants[$slug]);
        }
    }
}
