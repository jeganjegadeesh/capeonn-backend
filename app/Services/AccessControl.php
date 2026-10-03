<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Applies a permission's scope (all / department / team / self) to user records.
 * Every check is also limited to the actor's own company.
 */
class AccessControl
{
    /**
     * IDs of everyone below $user in the reporting chain (direct and indirect reports).
     * Loops over levels, so it is safe even if bad data creates a reporting cycle.
     *
     * @return list<int>
     */
    public function subordinateIds(User $user): array
    {
        $found = [];
        $frontier = [(int) $user->id];

        while ($frontier) {
            $next = User::whereIn('reports_to_id', $frontier)->pluck('id')
                ->map(fn ($id) => (int) $id)->all();

            $next = array_values(array_diff($next, $found, [(int) $user->id]));
            $found = array_merge($found, $next);
            $frontier = $next;
        }

        return $found;
    }

    /** Limit a users query to the records $actor may reach with $permission. */
    public function constrainUsers(Builder $query, User $actor, string $permission): Builder
    {
        $scope = $actor->scopeFor($permission);

        if ($scope === null || $actor->company_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('users.company_id', $actor->company_id);

        return match ($scope) {
            Permission::SCOPE_ALL        => $query,
            Permission::SCOPE_DEPARTMENT => $actor->department_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where('users.department_id', $actor->department_id),
            Permission::SCOPE_TEAM       => $query->whereIn('users.id', $this->subordinateIds($actor)),
            Permission::SCOPE_SELF       => $query->where('users.id', $actor->id),
            default                      => $query->whereRaw('1 = 0'),
        };
    }

    /** Limit any query having a user_id column according to the actor's permission scope. */
    public function constrainByUserId(Builder $query, User $actor, string $permission, string $userColumn = 'user_id'): Builder
    {
        $scope = $actor->scopeFor($permission);

        if ($scope === null || $actor->company_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();
        $query->where("{$table}.company_id", $actor->company_id);

        $qualifiedUserCol = str_contains($userColumn, '.') ? $userColumn : "{$table}.{$userColumn}";

        return match ($scope) {
            Permission::SCOPE_ALL        => $query,
            Permission::SCOPE_DEPARTMENT => $actor->department_id === null
                ? $query->whereRaw('1 = 0')
                : $query->whereIn($qualifiedUserCol, function ($sub) use ($actor) {
                    $sub->select('id')->from('users')->where('department_id', $actor->department_id)->whereNull('deleted_at');
                }),
            Permission::SCOPE_TEAM       => $query->whereIn($qualifiedUserCol, $this->subordinateIds($actor)),
            Permission::SCOPE_SELF       => $query->where($qualifiedUserCol, $actor->id),
            default                      => $query->whereRaw('1 = 0'),
        };
    }

    /** Single-record version of constrainUsers(). */
    public function canAccessUser(User $actor, User $target, string $permission): bool
    {
        $scope = $actor->scopeFor($permission);

        if ($scope === null
            || $actor->company_id === null
            || (int) $target->company_id !== (int) $actor->company_id) {
            return false;
        }

        return match ($scope) {
            Permission::SCOPE_ALL        => true,
            Permission::SCOPE_DEPARTMENT => $actor->department_id !== null
                && (int) $target->department_id === (int) $actor->department_id,
            Permission::SCOPE_TEAM       => in_array((int) $target->id, $this->subordinateIds($actor), true),
            Permission::SCOPE_SELF       => (int) $target->id === (int) $actor->id,
            default                      => false,
        };
    }

    /**
     * May $actor create/edit/deactivate $target?
     * Needs employees.manage over that record AND a higher role level than the target,
     * so a Manager can't edit another Manager or an Admin. Admins can manage anyone in the company.
     */
    public function canManageUser(User $actor, User $target): bool
    {
        if (! $this->canAccessUser($actor, $target, 'employees.manage')) {
            return false;
        }

        return $actor->hasRole(Role::SUPER_ADMIN, 'admin') || $this->roleLevel($target) < $this->roleLevel($actor);
    }

    /** Roles $actor may give to someone else: Super Admin any role, everyone else only lower roles. */
    public function assignableRoles(User $actor): Collection
    {
        if ($actor->hasRole(Role::SUPER_ADMIN, 'admin')) {
            return Role::orderByDesc('level')->get();
        }

        return Role::where('level', '<', $this->roleLevel($actor))->orderByDesc('level')->get();
    }

    private function roleLevel(User $user): int
    {
        return (int) ($user->role?->level ?? 0);
    }

    /** Constrain a projects query based on the actor's permission scope */
    /** Constrain a projects query based on the actor's permission scope */
    public function constrainProjects(Builder $query, User $actor, string $permission = 'projects.view'): Builder
    {
        $scope = $actor->scopeFor($permission);

        if ($scope === null || $actor->company_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('projects.company_id', $actor->company_id);

        return match ($scope) {
            Permission::SCOPE_ALL        => $query,
            Permission::SCOPE_DEPARTMENT => $query->where(function ($sub) use ($actor) {
                if ($actor->department_id !== null) {
                    $sub->where('projects.department_id', $actor->department_id);
                }
                $sub->orWhere('projects.manager_id', $actor->id)
                    ->orWhere('projects.team_lead_id', $actor->id)
                    ->orWhereHas('members', fn ($m) => $m->where('users.id', $actor->id));
            }),
            Permission::SCOPE_ASSIGNED   => $query->where(function ($sub) use ($actor) {
                $sub->where('projects.team_lead_id', $actor->id)
                    ->orWhere('projects.manager_id', $actor->id)
                    ->orWhereHas('members', fn ($m) => $m->where('users.id', $actor->id));
            }),
            Permission::SCOPE_TEAM       => $query->whereIn('projects.team_lead_id', $this->subordinateIds($actor)),
            Permission::SCOPE_SELF       => $query->where('projects.team_lead_id', $actor->id),
            default                      => $query->whereRaw('1 = 0'),
        };
    }

    /** Check if an actor can access a specific project with the given permission */
    public function canAccessProject(User $actor, \App\Models\Project $project, string $permission = 'projects.view'): bool
    {
        $scope = $actor->scopeFor($permission);

        if ($scope === null
            || $actor->company_id === null
            || (int) $project->company_id !== (int) $actor->company_id) {
            return false;
        }

        return match ($scope) {
            Permission::SCOPE_ALL        => true,
            Permission::SCOPE_DEPARTMENT => ($actor->department_id !== null && (int) $project->department_id === (int) $actor->department_id)
                || (int) $project->manager_id === (int) $actor->id
                || (int) $project->team_lead_id === (int) $actor->id
                || $project->members()->where('users.id', $actor->id)->exists(),
            Permission::SCOPE_ASSIGNED   => (int) $project->team_lead_id === (int) $actor->id
                || (int) $project->manager_id === (int) $actor->id
                || $project->members()->where('users.id', $actor->id)->exists(),
            Permission::SCOPE_TEAM       => in_array((int) $project->team_lead_id, $this->subordinateIds($actor), true),
            Permission::SCOPE_SELF       => (int) $project->team_lead_id === (int) $actor->id,
            default                      => false,
        };
    }

    /** May the actor edit this project? (Admins, or Managers in own department/manager_id) */
    public function canManageProject(User $actor, \App\Models\Project $project): bool
    {
        if ($actor->hasRole(Role::SUPER_ADMIN, 'admin')) {
            return true;
        }

        if ($actor->hasPermission('projects.manage')) {
            return ($actor->department_id !== null && (int) $project->department_id === (int) $actor->department_id)
                || (int) $project->manager_id === (int) $actor->id;
        }

        return false;
    }

    /** May the actor assign or change the Team Lead on this project? (Managers own dept, Admin) */
    public function canAssignLead(User $actor, \App\Models\Project $project): bool
    {
        if ($actor->hasRole(Role::SUPER_ADMIN, 'admin')) {
            return true;
        }

        if ($actor->hasPermission('projects.assign')) {
            return ($actor->department_id !== null && (int) $project->department_id === (int) $actor->department_id)
                || (int) $project->manager_id === (int) $actor->id;
        }

        return false;
    }

    /** May the actor assign lead or manage team members? (Legacy helper) */
    public function canAssignProject(User $actor, \App\Models\Project $project): bool
    {
        return $this->canAssignLead($actor, $project) || $this->canManageProjectTeam($actor, $project);
    }

    /**
     * May the actor add, update, or remove members from this project?
     * Team Lead can manage members on their own project.
     * Managers can manage on their department's projects.
     * Admins can manage all.
     */
    public function canManageProjectTeam(User $actor, \App\Models\Project $project): bool
    {
        if ($actor->company_id === null || (int) $project->company_id !== (int) $actor->company_id) {
            return false;
        }

        // On Hold, Completed, Archived, and Cancelled projects accept no new members or changes
        if (in_array(strtolower($project->status), ['on_hold', 'completed', 'archived', 'cancelled'], true)) {
            return false;
        }

        if ($actor->hasRole(Role::SUPER_ADMIN, 'admin')) {
            return true;
        }

        $scope = $actor->scopeFor('projects.team');
        if ($scope === null) {
            return false;
        }

        if ($scope === Permission::SCOPE_ALL) {
            return true;
        }

        if ($scope === Permission::SCOPE_DEPARTMENT) {
            return ($actor->department_id !== null && (int) $project->department_id === (int) $actor->department_id)
                || (int) $project->manager_id === (int) $actor->id;
        }

        if ($scope === Permission::SCOPE_ASSIGNED) {
            // Team Lead can manage team only on projects where they are assigned as Team Lead
            return (int) $project->team_lead_id === (int) $actor->id;
        }

        return false;
    }

    /**
     * Audit log visibility: Super Admin, Manager (own department), and Team Lead (own project).
     * Regular employees and HR cannot view activity logs.
     */
    public function canViewProjectActivity(User $actor, \App\Models\Project $project): bool
    {
        if ($actor->company_id === null || (int) $project->company_id !== (int) $actor->company_id) {
            return false;
        }

        if ($actor->hasRole(Role::SUPER_ADMIN, 'admin')) {
            return true;
        }

        if (! $actor->hasPermission('projects.activity')) {
            return false;
        }

        $scope = $actor->scopeFor('projects.activity');
        if ($scope === Permission::SCOPE_ALL) {
            return true;
        }

        if ($scope === Permission::SCOPE_DEPARTMENT) {
            return ($actor->department_id !== null && (int) $project->department_id === (int) $actor->department_id)
                || (int) $project->manager_id === (int) $actor->id;
        }

        if ($scope === Permission::SCOPE_ASSIGNED) {
            // Team lead on own project only
            return (int) $project->team_lead_id === (int) $actor->id;
        }

        return false;
    }

    /**
     * Delete is restricted to Super Admin / Admin only, and only if project is empty (no members).
     */
    public function canDeleteProject(User $actor, \App\Models\Project $project): bool
    {
        if (! $actor->hasRole(Role::SUPER_ADMIN, 'admin')) {
            return false;
        }

        // Empty check: no members
        if ($project->members()->exists()) {
            return false;
        }

        return true;
    }
}
