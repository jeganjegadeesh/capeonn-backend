<?php

namespace Tests\Feature\Organization;

use App\Models\Role;

class DepartmentTest extends OrganizationTestCase
{
    public function test_admin_creates_department_with_uppercased_code(): void
    {
        $this->actingAsRole(Role::ADMIN);

        $this->postJson('/api/v1/departments', ['name' => 'Engineering', 'code' => 'eng', 'description' => 'Builds things'])
            ->assertStatus(201)
            ->assertJsonPath('data.code', 'ENG')
            ->assertJsonPath('data.employees_count', 0);

        $this->assertDatabaseHas('departments', ['company_id' => $this->company->id, 'code' => 'ENG']);
    }

    public function test_duplicate_code_and_name_are_rejected_within_a_company(): void
    {
        $this->makeDepartment(['name' => 'Engineering', 'code' => 'ENG']);
        $this->actingAsRole(Role::ADMIN);

        $this->postJson('/api/v1/departments', ['name' => 'Sales', 'code' => 'eng'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/departments', ['name' => 'Engineering', 'code' => 'ENG2'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_same_code_is_allowed_in_a_different_company(): void
    {
        $this->makeDepartment(['name' => 'Engineering', 'code' => 'ENG'], $this->otherCompany);
        $this->actingAsRole(Role::ADMIN);

        $this->postJson('/api/v1/departments', ['name' => 'Engineering', 'code' => 'ENG'])->assertStatus(201);
    }

    public function test_manager_can_list_but_not_create(): void
    {
        $this->actingAsRole(Role::MANAGER);

        $this->getJson('/api/v1/departments')->assertOk();
        $this->postJson('/api/v1/departments', ['name' => 'X', 'code' => 'X'])->assertStatus(403);
    }

    public function test_team_lead_and_employee_cannot_list(): void
    {
        $this->actingAsRole(Role::TEAM_LEAD);
        $this->getJson('/api/v1/departments')->assertStatus(403);

        $this->actingAsRole(Role::EMPLOYEE);
        $this->getJson('/api/v1/departments')->assertStatus(403);
    }

    public function test_list_is_company_scoped_paginated_and_searchable(): void
    {
        $alpha = $this->makeDepartment(['name' => 'Alpha', 'code' => 'ALP']);
        $this->makeDepartment(['name' => 'Beta', 'code' => 'BET']);
        $this->makeDepartment(['name' => 'Gamma', 'code' => 'GAM']);
        $this->makeDepartment(['name' => 'Foreign', 'code' => 'FOR'], $this->otherCompany);

        $admin = $this->actingAsRole(Role::ADMIN);
        $admin->update(['department_id' => $alpha->id]);

        $page = $this->getJson('/api/v1/departments?per_page=2')->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertSame(3, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.last_page'));

        $search = $this->getJson('/api/v1/departments?search=alp')->assertOk();
        $this->assertSame(['Alpha'], array_column($search->json('data'), 'name'));
        $this->assertSame(1, $search->json('data.0.employees_count'));

        $this->getJson('/api/v1/departments?search=Foreign')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_other_companys_department_is_a_404(): void
    {
        $foreign = $this->makeDepartment([], $this->otherCompany);
        $this->actingAsRole(Role::ADMIN);

        $this->getJson("/api/v1/departments/{$foreign->id}")->assertStatus(404);
        $this->putJson("/api/v1/departments/{$foreign->id}", ['name' => 'Hijack', 'code' => 'HJ'])->assertStatus(404);
        $this->deleteJson("/api/v1/departments/{$foreign->id}")->assertStatus(404);
    }

    public function test_update_can_keep_its_own_code_and_name(): void
    {
        $dept = $this->makeDepartment(['name' => 'Engineering', 'code' => 'ENG']);
        $this->actingAsRole(Role::ADMIN);

        $this->putJson("/api/v1/departments/{$dept->id}", ['name' => 'Engineering', 'code' => 'ENG', 'description' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Updated');
    }

    public function test_head_must_be_an_active_manager_or_admin_of_the_same_company(): void
    {
        $dept = $this->makeDepartment();
        $this->actingAsRole(Role::ADMIN);
        $payload = ['name' => $dept->name, 'code' => $dept->code];

        $employee = $this->makeUser(Role::EMPLOYEE);
        $this->putJson("/api/v1/departments/{$dept->id}", $payload + ['head_user_id' => $employee->id])
            ->assertStatus(422)->assertJsonValidationErrors('head_user_id');

        $foreignManager = $this->makeUser(Role::MANAGER, [], $this->otherCompany);
        $this->putJson("/api/v1/departments/{$dept->id}", $payload + ['head_user_id' => $foreignManager->id])
            ->assertStatus(422)->assertJsonValidationErrors('head_user_id');

        $inactiveManager = $this->makeUser(Role::MANAGER, ['is_active' => false]);
        $this->putJson("/api/v1/departments/{$dept->id}", $payload + ['head_user_id' => $inactiveManager->id])
            ->assertStatus(422)->assertJsonValidationErrors('head_user_id');

        $manager = $this->makeUser(Role::MANAGER);
        $this->putJson("/api/v1/departments/{$dept->id}", $payload + ['head_user_id' => $manager->id])
            ->assertOk()->assertJsonPath('data.head.id', $manager->id);
    }

    public function test_cannot_delete_department_with_employees(): void
    {
        $dept = $this->makeDepartment();
        $this->makeUser(Role::EMPLOYEE, ['department_id' => $dept->id]);
        $this->actingAsRole(Role::ADMIN);

        $this->deleteJson("/api/v1/departments/{$dept->id}")->assertStatus(409);
        $this->assertNotSoftDeleted($dept);
    }

    public function test_empty_department_can_be_deleted_and_its_code_stays_reserved(): void
    {
        $dept = $this->makeDepartment(['name' => 'Old', 'code' => 'OLD']);
        $this->actingAsRole(Role::ADMIN);

        $this->deleteJson("/api/v1/departments/{$dept->id}")->assertOk();
        $this->assertSoftDeleted($dept);
        $this->getJson("/api/v1/departments/{$dept->id}")->assertStatus(404);

        $this->postJson('/api/v1/departments', ['name' => 'New', 'code' => 'OLD'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_non_numeric_id_is_a_404(): void
    {
        $this->actingAsRole(Role::ADMIN);

        $this->getJson('/api/v1/departments/abc')->assertStatus(404);
    }
}
