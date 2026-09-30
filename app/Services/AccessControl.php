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

        return $actor->hasRole(Role::ADMIN) || $this->roleLevel($target) < $this->roleLevel($actor);
    }

    /** Roles $actor may give to someone else: Admin any role, everyone else only lower roles. */
    public function assignableRoles(User $actor): Collection
    {
        if ($actor->hasRole(Role::ADMIN)) {
            return Role::orderByDesc('level')->get();
        }

        return Role::where('level', '<', $this->roleLevel($actor))->orderByDesc('level')->get();
    }

    private function roleLevel(User $user): int
    {
        return (int) ($user->role?->level ?? 0);
    }
}
