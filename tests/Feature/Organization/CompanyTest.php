<?php

namespace Tests\Feature\Organization;

use App\Models\Role;

class CompanyTest extends OrganizationTestCase
{
    public function test_manager_can_view_own_company(): void
    {
        $this->makeDepartment();
        $this->actingAsRole(Role::MANAGER);

        $this->getJson('/api/v1/company')
            ->assertOk()
            ->assertJsonPath('data.code', 'TST')
            ->assertJsonPath('data.departments_count', 1)
            ->assertJsonPath('data.employees_count', 1);
    }

    public function test_employee_cannot_view_company(): void
    {
        $this->actingAsRole(Role::EMPLOYEE);

        $this->getJson('/api/v1/company')->assertStatus(403);
    }

    public function test_admin_can_update_company(): void
    {
        $this->actingAsRole(Role::ADMIN);

        $this->putJson('/api/v1/company', [
            'name'     => 'Capeonn Pvt Ltd',
            'email'    => 'hello@capeonn.test',
            'timezone' => 'Asia/Kolkata',
            'code'     => 'HACKED', // must be ignored
        ])->assertOk()->assertJsonPath('data.name', 'Capeonn Pvt Ltd');

        $this->assertDatabaseHas('companies', ['id' => $this->company->id, 'name' => 'Capeonn Pvt Ltd', 'code' => 'TST']);
        $this->assertDatabaseHas('companies', ['id' => $this->otherCompany->id, 'name' => 'Other Co']);
    }

    public function test_manager_cannot_update_company(): void
    {
        $this->actingAsRole(Role::MANAGER);

        $this->putJson('/api/v1/company', ['name' => 'X', 'timezone' => 'Asia/Kolkata'])->assertStatus(403);
    }

    public function test_update_validates_input(): void
    {
        $this->actingAsRole(Role::ADMIN);

        $this->putJson('/api/v1/company', ['name' => '', 'timezone' => 'Mars/Olympus', 'email' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'timezone', 'email']);
    }
}
