<?php

namespace Tests\Feature\Employees;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\OrganizationTestCase;

class EmployeeTest extends OrganizationTestCase
{
    private Department $dept;
    private Department $otherDept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept      = $this->makeDepartment(['name' => 'Engineering', 'code' => 'ENG']);
        $this->otherDept = $this->makeDepartment(['name' => 'Sales', 'code' => 'SAL']);
    }

    private function roleId(string $slug): int
    {
        return (int) Role::where('slug', $slug)->value('id');
    }

    private function as(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    /** A user in the given department (defaults to Engineering). */
    private function person(string $role, ?User $reportsTo = null, ?Department $dept = null, array $extra = []): User
    {
        return $this->makeUser($role, array_merge([
            'department_id' => ($dept ?? $this->dept)->id,
            'reports_to_id' => $reportsTo?->id,
        ], $extra));
    }

    private function payload(array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return array_merge([
            'name'          => "New Person $n",
            'email'         => "new{$n}@example.com",
            'password'      => 'Password@123',
            'role_id'       => $this->roleId(Role::EMPLOYEE),
            'department_id' => $this->dept->id,
        ], $overrides);
    }

    private function idsOf($response): array
    {
        $ids = array_column($response->json('data'), 'id');
        sort($ids);

        return $ids;
    }

    private function sorted(User ...$users): array
    {
        $ids = array_map(fn (User $u) => $u->id, $users);
        sort($ids);

        return $ids;
    }

    // ------------------------------------------------------------ create

    public function test_admin_creates_employee_with_generated_code_and_hashed_password(): void
    {
        $this->as($this->makeUser(Role::ADMIN));

        $response = $this->postJson('/api/v1/employees', $this->payload(['email' => 'Jane@Example.com']))
            ->assertStatus(201)
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.role.slug', 'employee')
            ->assertJsonPath('data.department.name', 'Engineering');

        $this->assertMatchesRegularExpression('/^TST-\d{4}$/', $response->json('data.employee_code'));

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Password@123', $user->password));
        $this->assertSame($this->company->id, $user->company_id);
    }

    public function test_manager_creates_in_own_department_by_default_and_can_assign_lower_roles(): void
    {
        $this->as($this->person(Role::MANAGER));

        $this->postJson('/api/v1/employees', $this->payload([
            'role_id' => $this->roleId(Role::TEAM_LEAD),
            'department_id' => null,
        ]))->assertStatus(201)->assertJsonPath('data.department.id', $this->dept->id);
    }

    public function test_manager_cannot_create_in_another_department(): void
    {
        $this->as($this->person(Role::MANAGER));

        $this->postJson('/api/v1/employees', $this->payload(['department_id' => $this->otherDept->id]))
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_manager_cannot_assign_manager_or_admin_roles(): void
    {
        $this->as($this->person(Role::MANAGER));

        foreach ([Role::MANAGER, Role::ADMIN] as $role) {
            $this->postJson('/api/v1/employees', $this->payload(['role_id' => $this->roleId($role)]))
                ->assertStatus(422)->assertJsonValidationErrors('role_id');
        }
    }

    public function test_team_lead_and_employee_cannot_create(): void
    {
        $this->as($this->person(Role::TEAM_LEAD));
        $this->postJson('/api/v1/employees', $this->payload())->assertStatus(403);

        $this->as($this->person(Role::EMPLOYEE));
        $this->postJson('/api/v1/employees', $this->payload())->assertStatus(403);
    }

    public function test_reports_to_must_be_a_higher_role(): void
    {
        $teamLead = $this->person(Role::TEAM_LEAD);
        $peer     = $this->person(Role::EMPLOYEE);
        $this->as($this->makeUser(Role::ADMIN));

        $this->postJson('/api/v1/employees', $this->payload(['reports_to_id' => $peer->id]))
            ->assertStatus(422)->assertJsonValidationErrors('reports_to_id');

        $this->postJson('/api/v1/employees', $this->payload(['reports_to_id' => $teamLead->id]))
            ->assertStatus(201)->assertJsonPath('data.reports_to.id', $teamLead->id);
    }

    public function test_manager_must_pick_supervisor_from_own_department(): void
    {
        $foreignLead = $this->person(Role::TEAM_LEAD, null, $this->otherDept);
        $this->as($this->person(Role::MANAGER));

        $this->postJson('/api/v1/employees', $this->payload(['reports_to_id' => $foreignLead->id]))
            ->assertStatus(422)->assertJsonValidationErrors('reports_to_id');
    }

    public function test_duplicate_email_and_foreign_department_are_rejected(): void
    {
        $existing = $this->person(Role::EMPLOYEE);
        $foreignDept = $this->makeDepartment([], $this->otherCompany);
        $this->as($this->makeUser(Role::ADMIN));

        $this->postJson('/api/v1/employees', $this->payload(['email' => $existing->email]))
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/employees', $this->payload(['department_id' => $foreignDept->id]))
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->as($this->makeUser(Role::ADMIN));

        $this->postJson('/api/v1/employees', $this->payload(['password' => 'weak']))
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    // ------------------------------------------------------------ list & show

    public function test_each_role_only_lists_what_its_scope_allows(): void
    {
        $manager = $this->person(Role::MANAGER);
        $lead    = $this->person(Role::TEAM_LEAD, $manager);
        $emp1    = $this->person(Role::EMPLOYEE, $lead);
        $emp2    = $this->person(Role::EMPLOYEE, $lead);
        $sales   = $this->person(Role::EMPLOYEE, null, $this->otherDept);
        $foreign = $this->makeUser(Role::EMPLOYEE, [], $this->otherCompany);
        $admin   = $this->makeUser(Role::ADMIN);

        $this->as($admin);
        $all = $this->idsOf($this->getJson('/api/v1/employees')->assertOk());
        $this->assertSame($this->sorted($admin, $manager, $lead, $emp1, $emp2, $sales), $all);
        $this->assertNotContains($foreign->id, $all);

        $this->as($manager);
        $this->assertSame($this->sorted($manager, $lead, $emp1, $emp2), $this->idsOf($this->getJson('/api/v1/employees')));

        $this->as($lead);
        $this->assertSame($this->sorted($emp1, $emp2), $this->idsOf($this->getJson('/api/v1/employees')));

        $this->as($emp1);
        $this->assertSame($this->sorted($emp1), $this->idsOf($this->getJson('/api/v1/employees')));
    }

    public function test_list_filters_and_pagination(): void
    {
        $this->person(Role::EMPLOYEE, null, null, ['name' => 'Alice Alpha']);
        $this->person(Role::EMPLOYEE, null, null, ['name' => 'Bob Beta']);
        $this->person(Role::TEAM_LEAD, null, null, ['name' => 'Cara Gamma']);
        $this->as($this->makeUser(Role::ADMIN, ['name' => 'Zed Admin']));

        $search = $this->getJson('/api/v1/employees?search=alpha')->assertOk();
        $this->assertSame(['Alice Alpha'], array_column($search->json('data'), 'name'));

        $byRole = $this->getJson('/api/v1/employees?role=team_lead')->assertOk();
        $this->assertSame(['Cara Gamma'], array_column($byRole->json('data'), 'name'));

        $page = $this->getJson('/api/v1/employees?per_page=2&page=2')->assertOk();
        $this->assertSame(4, $page->json('meta.total'));
        $this->assertCount(2, $page->json('data'));
    }

    public function test_show_respects_scope(): void
    {
        $manager = $this->person(Role::MANAGER);
        $inDept  = $this->person(Role::EMPLOYEE);
        $sales   = $this->person(Role::EMPLOYEE, null, $this->otherDept);
        $foreign = $this->makeUser(Role::EMPLOYEE, [], $this->otherCompany);

        $this->as($manager);
        $this->getJson("/api/v1/employees/{$inDept->id}")->assertOk()->assertJsonPath('data.id', $inDept->id);
        $this->getJson("/api/v1/employees/{$sales->id}")->assertStatus(404);
        $this->getJson("/api/v1/employees/{$foreign->id}")->assertStatus(404);
    }

    // ------------------------------------------------------------ update

    public function test_manager_updates_an_employee_in_own_department(): void
    {
        $manager  = $this->person(Role::MANAGER);
        $employee = $this->person(Role::EMPLOYEE);
        $this->as($manager);

        $this->putJson("/api/v1/employees/{$employee->id}", ['phone' => '9876543210'])
            ->assertOk()->assertJsonPath('data.phone', '9876543210');
    }

    public function test_manager_cannot_update_peer_manager_admin_or_other_department(): void
    {
        $manager = $this->person(Role::MANAGER);
        $peer    = $this->person(Role::MANAGER);
        $admin   = $this->makeUser(Role::ADMIN);
        $sales   = $this->person(Role::EMPLOYEE, null, $this->otherDept);
        $this->as($manager);

        foreach ([$peer, $admin, $sales] as $target) {
            $this->putJson("/api/v1/employees/{$target->id}", ['phone' => '111'])->assertStatus(403);
        }
    }

    public function test_manager_cannot_move_an_employee_to_another_department(): void
    {
        $manager  = $this->person(Role::MANAGER);
        $employee = $this->person(Role::EMPLOYEE);
        $this->as($manager);

        $this->putJson("/api/v1/employees/{$employee->id}", ['department_id' => $this->otherDept->id])
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_update_of_unknown_or_foreign_employee_is_404(): void
    {
        $foreign = $this->makeUser(Role::EMPLOYEE, [], $this->otherCompany);
        $this->as($this->makeUser(Role::ADMIN));

        $this->putJson("/api/v1/employees/{$foreign->id}", ['phone' => '1'])->assertStatus(404);
        $this->putJson('/api/v1/employees/999999', ['phone' => '1'])->assertStatus(404);
    }

    public function test_password_cannot_be_changed_through_update(): void
    {
        $employee = $this->person(Role::EMPLOYEE);
        $this->as($this->makeUser(Role::ADMIN));

        $this->putJson("/api/v1/employees/{$employee->id}", ['password' => 'Password@999'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_admin_cannot_demote_or_deactivate_self_but_can_edit_profile(): void
    {
        $admin = $this->as($this->makeUser(Role::ADMIN));

        $this->putJson("/api/v1/employees/{$admin->id}", ['role_id' => $this->roleId(Role::EMPLOYEE)])
            ->assertStatus(422)->assertJsonValidationErrors('role_id');

        $this->putJson("/api/v1/employees/{$admin->id}", ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('is_active');

        $this->putJson("/api/v1/employees/{$admin->id}", ['name' => 'Renamed Admin'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed Admin');
    }

    public function test_deactivating_an_employee_revokes_their_tokens(): void
    {
        $employee = $this->person(Role::EMPLOYEE);
        $employee->createToken('phone');
        $employee->createToken('laptop');
        $this->as($this->makeUser(Role::ADMIN));

        $this->putJson("/api/v1/employees/{$employee->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $employee->tokens()->count());
    }

    public function test_role_change_that_would_leave_reports_outranking_is_rejected(): void
    {
        $lead = $this->person(Role::TEAM_LEAD);
        $this->person(Role::EMPLOYEE, $lead);
        $this->as($this->makeUser(Role::ADMIN));

        $this->putJson("/api/v1/employees/{$lead->id}", ['role_id' => $this->roleId(Role::EMPLOYEE)])
            ->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    // ------------------------------------------------------------ delete

    public function test_employee_is_soft_deleted_and_tokens_revoked(): void
    {
        $employee = $this->person(Role::EMPLOYEE);
        $employee->createToken('phone');
        $this->as($this->makeUser(Role::ADMIN));

        $this->deleteJson("/api/v1/employees/{$employee->id}")->assertOk();

        $this->assertSoftDeleted($employee);
        $this->assertSame(0, $employee->tokens()->count());
    }

    public function test_cannot_delete_someone_with_direct_reports(): void
    {
        $lead = $this->person(Role::TEAM_LEAD);
        $this->person(Role::EMPLOYEE, $lead);
        $this->as($this->makeUser(Role::ADMIN));

        $this->deleteJson("/api/v1/employees/{$lead->id}")->assertStatus(409);
        $this->assertNotSoftDeleted($lead);
    }

    public function test_cannot_delete_self_or_out_of_scope_users(): void
    {
        $admin = $this->as($this->makeUser(Role::ADMIN));
        $this->deleteJson("/api/v1/employees/{$admin->id}")->assertStatus(422);

        $manager = $this->as($this->person(Role::MANAGER));
        $sales   = $this->person(Role::EMPLOYEE, null, $this->otherDept);
        $this->deleteJson("/api/v1/employees/{$sales->id}")->assertStatus(403);
        $this->deleteJson("/api/v1/employees/{$manager->id}")->assertStatus(403);
    }

    public function test_deleting_a_department_head_clears_the_head_field(): void
    {
        $head = $this->person(Role::MANAGER);
        $this->dept->update(['head_user_id' => $head->id]);
        $this->as($this->makeUser(Role::ADMIN));

        $this->deleteJson("/api/v1/employees/{$head->id}")->assertOk();

        $this->assertNull($this->dept->fresh()->head_user_id);
    }

    public function test_roles_endpoint_returns_assignable_roles_by_role_level(): void
    {
        $this->as($this->makeUser(Role::ADMIN));
        $res = $this->getJson('/api/v1/roles')->assertOk();
        $this->assertCount(4, $res->json('data'));

        $this->as($this->person(Role::MANAGER));
        $res = $this->getJson('/api/v1/roles')->assertOk();
        $slugs = array_column($res->json('data'), 'slug');
        $this->assertEquals([Role::TEAM_LEAD, Role::EMPLOYEE], $slugs);
    }
}

