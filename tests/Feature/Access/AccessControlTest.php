<?php

namespace Tests\Feature\Access;

use App\Models\Company;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControl;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Org used below (company TST):
 *
 *   admin (no dept)
 *   Dept A: managerA -> tlA -> empA1, empA2      (+ managerA2, a peer manager)
 *   Dept B: managerB -> empB
 *   Company OTHER: outsider
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private AccessControl $access;
    private Company $company;
    private Department $deptA;
    private Department $deptB;

    private User $admin;
    private User $managerA;
    private User $managerA2;
    private User $tlA;
    private User $empA1;
    private User $empA2;
    private User $managerB;
    private User $empB;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->access = new AccessControl();

        $this->company = Company::create(['name' => 'Test Co', 'code' => 'TST']);
        $other = Company::create(['name' => 'Other Co', 'code' => 'OTH']);

        $this->deptA = Department::create(['company_id' => $this->company->id, 'name' => 'A', 'code' => 'A']);
        $this->deptB = Department::create(['company_id' => $this->company->id, 'name' => 'B', 'code' => 'B']);

        $this->admin     = $this->makeUser(Role::ADMIN);
        $this->managerA  = $this->makeUser(Role::MANAGER, $this->deptA);
        $this->managerA2 = $this->makeUser(Role::MANAGER, $this->deptA);
        $this->tlA       = $this->makeUser(Role::TEAM_LEAD, $this->deptA, $this->managerA);
        $this->empA1     = $this->makeUser(Role::EMPLOYEE, $this->deptA, $this->tlA);
        $this->empA2     = $this->makeUser(Role::EMPLOYEE, $this->deptA, $this->tlA);
        $this->managerB  = $this->makeUser(Role::MANAGER, $this->deptB);
        $this->empB      = $this->makeUser(Role::EMPLOYEE, $this->deptB, $this->managerB);
        $this->outsider  = $this->makeUser(Role::ADMIN, null, null, $other);
    }

    private function makeUser(string $role, ?Department $dept = null, ?User $reportsTo = null, ?Company $company = null): User
    {
        return User::factory()->create([
            'company_id'    => ($company ?? $this->company)->id,
            'department_id' => $dept?->id,
            'role_id'       => Role::where('slug', $role)->value('id'),
            'reports_to_id' => $reportsTo?->id,
            'is_active'     => true,
        ]);
    }

    private function visibleIds(User $actor, string $permission): array
    {
        $ids = $this->access->constrainUsers(User::query(), $actor, $permission)->pluck('id')->all();
        sort($ids);

        return $ids;
    }

    private function ids(User ...$users): array
    {
        $ids = array_map(fn (User $u) => $u->id, $users);
        sort($ids);

        return $ids;
    }

    // ---- scope: all / department / team / self ----

    public function test_admin_sees_whole_company_but_not_other_companies(): void
    {
        $ids = $this->visibleIds($this->admin, 'employees.view');

        $this->assertContains($this->empB->id, $ids);
        $this->assertNotContains($this->outsider->id, $ids);
        $this->assertCount(8, $ids);
    }

    public function test_manager_sees_own_department_only(): void
    {
        $this->assertSame(
            $this->ids($this->managerA, $this->managerA2, $this->tlA, $this->empA1, $this->empA2),
            $this->visibleIds($this->managerA, 'employees.view'),
        );
    }

    public function test_team_lead_sees_own_team_only(): void
    {
        $this->assertSame(
            $this->ids($this->empA1, $this->empA2),
            $this->visibleIds($this->tlA, 'employees.view'),
        );
    }

    public function test_employee_sees_only_self(): void
    {
        $this->assertSame($this->ids($this->empA1), $this->visibleIds($this->empA1, 'employees.view'));
    }

    public function test_manager_team_scope_covers_the_whole_downline(): void
    {
        // time.view is "team" for managers: TL plus the TL's employees.
        $this->assertSame(
            $this->ids($this->tlA, $this->empA1, $this->empA2),
            $this->visibleIds($this->managerA, 'time.view'),
        );
    }

    public function test_no_permission_means_no_records(): void
    {
        $this->assertSame([], $this->visibleIds($this->empA1, 'employees.manage'));
        $this->assertSame([], $this->visibleIds($this->admin, 'does.not.exist'));
    }

    public function test_manager_without_department_sees_nothing_in_department_scope(): void
    {
        $lonely = $this->makeUser(Role::MANAGER);

        $this->assertSame([], $this->visibleIds($lonely, 'employees.view'));
    }

    // ---- single-record checks ----

    public function test_can_access_user_matches_scopes(): void
    {
        $this->assertTrue($this->access->canAccessUser($this->managerA, $this->empA1, 'employees.view'));
        $this->assertFalse($this->access->canAccessUser($this->managerA, $this->empB, 'employees.view'));
        $this->assertTrue($this->access->canAccessUser($this->tlA, $this->empA2, 'employees.view'));
        $this->assertFalse($this->access->canAccessUser($this->tlA, $this->managerA, 'employees.view'));
        $this->assertFalse($this->access->canAccessUser($this->admin, $this->outsider, 'employees.view'));
    }

    // ---- managing users ----

    public function test_manager_can_manage_lower_roles_in_own_department(): void
    {
        $this->assertTrue($this->access->canManageUser($this->managerA, $this->tlA));
        $this->assertTrue($this->access->canManageUser($this->managerA, $this->empA1));
    }

    public function test_manager_cannot_manage_other_departments_peers_or_self(): void
    {
        $this->assertFalse($this->access->canManageUser($this->managerA, $this->empB));
        $this->assertFalse($this->access->canManageUser($this->managerA, $this->managerA2));
        $this->assertFalse($this->access->canManageUser($this->managerA, $this->managerA));
    }

    public function test_admin_can_manage_anyone_in_company_but_not_other_companies(): void
    {
        $this->assertTrue($this->access->canManageUser($this->admin, $this->managerB));
        $this->assertFalse($this->access->canManageUser($this->admin, $this->outsider));
    }

    public function test_team_lead_and_employee_cannot_manage_users(): void
    {
        $this->assertFalse($this->access->canManageUser($this->tlA, $this->empA1));
        $this->assertFalse($this->access->canManageUser($this->empA1, $this->empA2));
    }

    // ---- assignable roles ----

    public function test_assignable_roles_depend_on_actor_level(): void
    {
        $slugs = fn (User $u) => $this->access->assignableRoles($u)->pluck('slug')->all();

        $this->assertSame(['super_admin', 'admin', 'hr', 'manager', 'team_lead', 'employee'], $slugs($this->admin));
        $this->assertSame(['team_lead', 'employee'], $slugs($this->managerA));
        $this->assertSame(['employee'], $slugs($this->tlA));
        $this->assertSame([], $slugs($this->empA1));
    }

    // ---- hierarchy ----

    public function test_subordinate_lookup_survives_a_reporting_cycle(): void
    {
        // Bad data: A reports to B and B reports to A.
        $this->managerA2->update(['reports_to_id' => $this->managerB->id]);
        $this->managerB->update(['reports_to_id' => $this->managerA2->id]);

        $this->assertSame([$this->managerB->id, $this->empB->id], $this->access->subordinateIds($this->managerA2));
    }
}
