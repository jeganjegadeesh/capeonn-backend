<?php

namespace Tests\Feature\Employees;

use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\OrganizationTestCase;

class EmployeeProfileTest extends OrganizationTestCase
{
    private function as(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_can_view_profile(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $emp = $this->makeUser(Role::EMPLOYEE, [
            'dob' => '1995-05-15',
            'gender' => 'female',
            'skills' => ['Flutter', 'Dart', 'Laravel'],
        ]);

        $this->as($admin);

        $res = $this->getJson("/api/v1/employees/{$emp->id}/profile");
        $res->assertOk()
            ->assertJsonPath('data.id', $emp->id)
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.skills', ['Flutter', 'Dart', 'Laravel']);
    }

    public function test_employee_can_update_own_profile(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $this->as($emp);

        $res = $this->putJson("/api/v1/employees/{$emp->id}/profile", [
            'dob'                     => '1996-08-20',
            'gender'                  => 'male',
            'address'                 => '123 Tech Park Rd',
            'emergency_contact_name'  => 'John Doe',
            'emergency_contact_phone' => '9876543210',
            'skills'                  => ['Python', 'Docker'],
            'certifications'          => [['name' => 'AWS Certified', 'issuer' => 'Amazon', 'date' => '2025']],
        ]);

        $res->assertOk()
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.address', '123 Tech Park Rd')
            ->assertJsonPath('data.emergency_contact_name', 'John Doe')
            ->assertJsonPath('data.skills', ['Python', 'Docker']);

        $emp->refresh();
        $this->assertSame('male', $emp->gender);
        $this->assertSame('123 Tech Park Rd', $emp->address);
    }

    public function test_employee_cannot_update_other_employee_profile(): void
    {
        $emp1 = $this->makeUser(Role::EMPLOYEE);
        $emp2 = $this->makeUser(Role::EMPLOYEE);

        $this->as($emp1);

        $res = $this->putJson("/api/v1/employees/{$emp2->id}/profile", [
            'address' => 'Hacked Address',
        ]);

        $res->assertStatus(403);
    }
}
