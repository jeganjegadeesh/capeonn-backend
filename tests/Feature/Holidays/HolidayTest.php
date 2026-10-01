<?php

namespace Tests\Feature\Holidays;

use App\Models\Holiday;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\OrganizationTestCase;

class HolidayTest extends OrganizationTestCase
{
    private function as(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_can_list_holidays(): void
    {
        Holiday::create([
            'company_id'   => $this->company->id,
            'name'         => "New Year's Day",
            'date'         => '2026-01-01',
            'holiday_type' => 'national',
        ]);

        $emp = $this->makeUser(Role::EMPLOYEE);
        $this->as($emp);

        $res = $this->getJson('/api/v1/holidays?year=2026');
        $res->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', "New Year's Day");
    }

    public function test_admin_can_manage_holidays(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->as($admin);

        $createRes = $this->postJson('/api/v1/holidays', [
            'name'         => 'Company Foundation Day',
            'date'         => '2026-07-15',
            'holiday_type' => 'company',
            'description'  => 'Annual celebration',
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.name', 'Company Foundation Day');

        $holidayId = $createRes->json('data.id');

        $updateRes = $this->putJson("/api/v1/holidays/{$holidayId}", [
            'name'         => 'Capeonn Anniversary',
            'date'         => '2026-07-15',
            'holiday_type' => 'company',
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('data.name', 'Capeonn Anniversary');

        $deleteRes = $this->deleteJson("/api/v1/holidays/{$holidayId}");
        $deleteRes->assertOk();

        $this->assertDatabaseMissing('holidays', ['id' => $holidayId]);
    }
}
