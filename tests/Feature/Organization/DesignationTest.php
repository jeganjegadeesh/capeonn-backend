<?php

namespace Tests\Feature\Organization;

use App\Models\Designation;
use App\Models\Role;

class DesignationTest extends OrganizationTestCase
{
    private function makeDesignation(string $name, $company = null): Designation
    {
        return Designation::create(['company_id' => ($company ?? $this->company)->id, 'name' => $name]);
    }

    public function test_admin_creates_designation(): void
    {
        $this->actingAsRole(Role::ADMIN);

        $this->postJson('/api/v1/designations', ['name' => 'Software Engineer'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Software Engineer')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_duplicate_name_is_rejected_per_company_only(): void
    {
        $this->makeDesignation('Designer');
        $this->makeDesignation('Analyst', $this->otherCompany);
        $this->actingAsRole(Role::ADMIN);

        $this->postJson('/api/v1/designations', ['name' => 'Designer'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/designations', ['name' => 'Analyst'])->assertStatus(201);
    }

    public function test_manager_can_list_but_not_create(): void
    {
        $this->makeDesignation('Designer');
        $this->actingAsRole(Role::MANAGER);

        $this->getJson('/api/v1/designations')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/designations', ['name' => 'X'])->assertStatus(403);
    }

    public function test_list_only_contains_own_company_and_supports_search(): void
    {
        $this->makeDesignation('Designer');
        $this->makeDesignation('Developer');
        $this->makeDesignation('Foreign Role', $this->otherCompany);
        $this->actingAsRole(Role::ADMIN);

        $this->getJson('/api/v1/designations')->assertOk()->assertJsonPath('meta.total', 2);

        $found = $this->getJson('/api/v1/designations?search=desig')->assertOk();
        $this->assertSame(['Designer'], array_column($found->json('data'), 'name'));
    }

    public function test_other_companys_designation_is_a_404(): void
    {
        $foreign = $this->makeDesignation('Foreign Role', $this->otherCompany);
        $this->actingAsRole(Role::ADMIN);

        $this->getJson("/api/v1/designations/{$foreign->id}")->assertStatus(404);
        $this->deleteJson("/api/v1/designations/{$foreign->id}")->assertStatus(404);
    }

    public function test_update_and_deactivate(): void
    {
        $designation = $this->makeDesignation('Designer');
        $this->actingAsRole(Role::ADMIN);

        $this->putJson("/api/v1/designations/{$designation->id}", ['name' => 'Senior Designer', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Senior Designer')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_cannot_delete_designation_that_is_in_use(): void
    {
        $designation = $this->makeDesignation('Designer');
        $this->makeUser(Role::EMPLOYEE, ['designation_id' => $designation->id]);
        $this->actingAsRole(Role::ADMIN);

        $this->deleteJson("/api/v1/designations/{$designation->id}")->assertStatus(409);
        $this->assertDatabaseHas('designations', ['id' => $designation->id]);
    }

    public function test_unused_designation_can_be_deleted(): void
    {
        $designation = $this->makeDesignation('Designer');
        $this->actingAsRole(Role::ADMIN);

        $this->deleteJson("/api/v1/designations/{$designation->id}")->assertOk();
        $this->assertDatabaseMissing('designations', ['id' => $designation->id]);
    }
}
