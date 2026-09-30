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
            Role::ADMIN     => ['Admin', 100, 'Full access to the whole company'],
            Role::MANAGER   => ['Manager', 70, 'Manages a department and assigns projects to Team Leads'],
            Role::TEAM_LEAD => ['Team Lead', 50, 'Splits projects into tasks and assigns them to team members'],
            Role::EMPLOYEE  => ['Employee', 10, 'Works on assigned tasks'],
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
            'organization.view'  => 'View company and departments',
            'organization.manage' => 'Create/edit company, departments and designations',
            'employees.view'     => 'View employee records',
            'employees.manage'   => 'Create/edit/deactivate employees',
            'projects.view'      => 'View projects',
            'projects.manage'    => 'Create/edit projects',
            'projects.assign'    => 'Assign projects to Team Leads',
            'tasks.view'         => 'View tasks',
            'tasks.manage'       => 'Create, assign and manage tasks',
            'tasks.update'       => 'Update status/progress of tasks',
            'time.view'          => 'View time tracking records',
            'time.track'         => 'Start/pause/complete own task timer',
            'chat.use'           => 'Use internal chat',
            'reports.view'       => 'View reports and dashboards',
            'attendance.view'    => 'View attendance records',
            'attendance.record'  => 'Check in / check out',
            'leave.view'         => 'View leave requests and balances',
            'leave.apply'        => 'Apply for leave',
            'leave.approve'      => 'Approve or reject leave',
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
        $adm = Role::ADMIN;
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
            'organization.view'   => [$adm => $all, $mgr => $all],
            'organization.manage' => [$adm => $all],

            'employees.view'      => [$adm => $all, $mgr => $dept, $tl => $team, $emp => $self],
            'employees.manage'    => [$adm => $all, $mgr => $dept],

            'projects.view'       => [$adm => $all, $mgr => $dept, $tl => $asg, $emp => $asg],
            'projects.manage'     => [$adm => $all, $mgr => $dept],
            'projects.assign'     => [$adm => $all, $mgr => $dept],

            'tasks.view'          => [$adm => $all, $mgr => $dept, $tl => $team, $emp => $asg],
            'tasks.manage'        => [$adm => $all, $tl => $team],
            'tasks.update'        => [$adm => $all, $tl => $team, $emp => $asg],

            'time.view'           => [$adm => $all, $mgr => $team, $tl => $team],
            'time.track'          => [$emp => $self],

            'chat.use'            => [$adm => $all, $mgr => $all, $tl => $all, $emp => $all],

            'reports.view'        => [$adm => $all, $mgr => $dept, $tl => $team],

            'attendance.view'     => [$adm => $all, $mgr => $dept, $tl => $team, $emp => $self],
            'attendance.record'   => [$adm => $self, $mgr => $self, $tl => $self, $emp => $self],

            'leave.view'          => [$adm => $all, $mgr => $dept, $tl => $team, $emp => $self],
            'leave.apply'         => [$adm => $self, $mgr => $self, $tl => $self, $emp => $self],
            'leave.approve'       => [$adm => $all, $mgr => $dept],
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
