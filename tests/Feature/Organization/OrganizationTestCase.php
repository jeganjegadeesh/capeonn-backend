<?php

namespace Tests\Feature\Organization;

use App\Models\Company;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class OrganizationTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->company = Company::create(['name' => 'Test Co', 'code' => 'TST']);
        $this->otherCompany = Company::create(['name' => 'Other Co', 'code' => 'OTH']);
    }

    protected function makeUser(string $role, array $overrides = [], ?Company $company = null): User
    {
        return User::factory()->create(array_merge([
            'company_id' => ($company ?? $this->company)->id,
            'role_id'    => Role::where('slug', $role)->value('id'),
            'is_active'  => true,
        ], $overrides));
    }

    protected function actingAsRole(string $role): User
    {
        $user = $this->makeUser($role);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function makeDepartment(array $overrides = [], ?Company $company = null): Department
    {
        static $n = 0;
        $n++;

        return Department::create(array_merge([
            'company_id' => ($company ?? $this->company)->id,
            'name'       => "Department $n",
            'code'       => "D$n",
        ], $overrides));
    }
}
