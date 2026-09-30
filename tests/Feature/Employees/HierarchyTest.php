<?php

namespace Tests\Feature\Employees;

use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\OrganizationTestCase;

class HierarchyTest extends OrganizationTestCase
{
    private User $admin;
    private User $manager;
    private User $lead;
    private User $emp1;
    private User $emp2;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = $this->makeDepartment();
        $mk = fn (string $role, string $name, ?User $boss = null) => $this->makeUser($role, [
            'name' => $name, 'department_id' => $dept->id, 'reports_to_id' => $boss?->id,
        ]);

        $this->admin   = $mk(Role::ADMIN, 'Aaron Admin');
        $this->manager = $mk(Role::MANAGER, 'Meera Manager');
        $this->lead    = $mk(Role::TEAM_LEAD, 'Tara Lead', $this->manager);
        $this->emp1    = $mk(Role::EMPLOYEE, 'Eli Employee', $this->lead);
        $this->emp2    = $mk(Role::EMPLOYEE, 'Esha Employee', $this->lead);

        $this->makeUser(Role::EMPLOYEE, ['name' => 'Foreign Person'], $this->otherCompany);
    }

    public function test_admin_sees_every_top_level_tree_in_the_company(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/hierarchy')->assertOk();

        $this->assertSame(['Aaron Admin', 'Meera Manager'], array_column($response->json('data'), 'name'));
        $tree = $response->json('data.1');
        $this->assertSame('Tara Lead', $tree['reports'][0]['name']);
        $this->assertSame(['Eli Employee', 'Esha Employee'], array_column($tree['reports'][0]['reports'], 'name'));
        $this->assertStringNotContainsString('Foreign Person', $response->getContent());
    }

    public function test_manager_sees_only_their_own_tree(): void
    {
        Sanctum::actingAs($this->manager);

        $response = $this->getJson('/api/v1/hierarchy')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Meera Manager', $response->json('data.0.name'));
        $this->assertSame('Tara Lead', $response->json('data.0.reports.0.name'));
    }

    public function test_team_lead_sees_themselves_and_their_team(): void
    {
        Sanctum::actingAs($this->lead);

        $response = $this->getJson('/api/v1/hierarchy')->assertOk();

        $this->assertSame('Tara Lead', $response->json('data.0.name'));
        $this->assertCount(2, $response->json('data.0.reports'));
        $this->assertStringNotContainsString('Meera Manager', $response->getContent());
    }

    public function test_employee_sees_only_themselves(): void
    {
        Sanctum::actingAs($this->emp1);

        $response = $this->getJson('/api/v1/hierarchy')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame([], $response->json('data.0.reports'));
    }

    public function test_inactive_people_are_hidden_unless_requested(): void
    {
        $this->emp2->update(['is_active' => false]);
        Sanctum::actingAs($this->manager);

        $default = $this->getJson('/api/v1/hierarchy')->assertOk();
        $this->assertCount(1, $default->json('data.0.reports.0.reports'));

        $all = $this->getJson('/api/v1/hierarchy?include_inactive=1')->assertOk();
        $this->assertCount(2, $all->json('data.0.reports.0.reports'));
    }
}
