<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\OfficeLocation;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\OrganizationTestCase;

class AttendanceTest extends OrganizationTestCase
{
    private OfficeLocation $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = OfficeLocation::create([
            'company_id'    => $this->company->id,
            'name'          => 'Main HQ',
            'latitude'      => 12.9716,
            'longitude'     => 77.5946,
            'radius_meters' => 500,
            'is_active'     => true,
        ]);
    }

    private function as(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_today_status_before_clock_in(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $this->as($emp);

        $res = $this->getJson('/api/v1/attendance/today');
        $res->assertOk()
            ->assertJsonPath('data.is_clocked_in', false)
            ->assertJsonPath('data.attendance', null);
    }

    public function test_clock_in_within_geofence(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $this->as($emp);

        // Within 10 meters of office
        $res = $this->postJson('/api/v1/attendance/clock-in', [
            'latitude'      => 12.971601,
            'longitude'     => 77.594601,
            'location_name' => 'HQ Reception',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.geofence_status', 'inside')
            ->assertJsonPath('data.is_flagged', false);

        $this->assertDatabaseHas('attendances', [
            'user_id'         => $emp->id,
            'geofence_status' => 'inside',
            'is_flagged'      => false,
        ]);
    }

    public function test_clock_in_outside_geofence_is_flagged(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $this->as($emp);

        // Far away from office (e.g. 50 km away)
        $res = $this->postJson('/api/v1/attendance/clock-in', [
            'latitude'      => 13.5000,
            'longitude'     => 78.0000,
            'location_name' => 'Remote / Home',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.geofence_status', 'outside')
            ->assertJsonPath('data.is_flagged', true);

        $this->assertDatabaseHas('attendances', [
            'user_id'         => $emp->id,
            'geofence_status' => 'outside',
            'is_flagged'      => true,
        ]);
    }

    public function test_cannot_clock_in_twice_on_same_day(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $this->as($emp);

        $this->postJson('/api/v1/attendance/clock-in')->assertStatus(201);
        $this->postJson('/api/v1/attendance/clock-in')->assertStatus(422);
    }

    public function test_clock_out_calculates_total_time(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $this->as($emp);

        // Create attendance clocked in 5 hours ago
        $att = Attendance::create([
            'user_id'            => $emp->id,
            'company_id'         => $this->company->id,
            'date'               => now()->toDateString(),
            'clock_in_at'        => now()->subHours(5),
            'status'             => 'present',
            'geofence_status'    => 'inside',
        ]);

        $res = $this->postJson('/api/v1/attendance/clock-out');
        $res->assertOk();

        $att->refresh();
        $this->assertNotNull($att->clock_out_at);
        $this->assertGreaterThanOrEqual(295, $att->total_minutes);
    }

    public function test_attendance_regularization_workflow(): void
    {
        $dept = $this->makeDepartment();
        $mgr = $this->makeUser(Role::MANAGER, ['department_id' => $dept->id]);
        $emp = $this->makeUser(Role::EMPLOYEE, ['department_id' => $dept->id, 'reports_to_id' => $mgr->id]);

        $this->as($emp);

        $yesterday = now()->subDay()->toDateString();
        $reqRes = $this->postJson('/api/v1/attendance/regularizations', [
            'date'                => $yesterday,
            'requested_clock_in'  => "{$yesterday} 09:00:00",
            'requested_clock_out' => "{$yesterday} 18:00:00",
            'reason'              => 'Forgot to check-in on system',
        ]);

        $reqRes->assertStatus(201)
            ->assertJsonPath('data.status', 'pending');

        $regId = $reqRes->json('data.id');

        // Manager approves
        $this->as($mgr);
        $approveRes = $this->putJson("/api/v1/attendance/regularizations/{$regId}/approve");
        $approveRes->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $att = Attendance::where('user_id', $emp->id)->whereDate('date', $yesterday)->first();
        $this->assertNotNull($att);
        $this->assertSame('present', $att->status);
    }
}
