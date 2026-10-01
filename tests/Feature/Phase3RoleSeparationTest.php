<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\EmployeeDocument;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase3RoleSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $dept;
    private User $superAdmin;
    private User $hr;
    private User $director;
    private User $manager;
    private User $teamLead;
    private User $employee;
    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Capeonn Inc', 'code' => 'CAP']);
        $this->dept = Department::create(['company_id' => $this->company->id, 'name' => 'Engineering', 'code' => 'ENG']);

        $superAdminRole = Role::where('slug', Role::SUPER_ADMIN)->firstOrFail();
        $hrRole = Role::where('slug', Role::HR)->firstOrFail();
        $managerRole = Role::where('slug', Role::MANAGER)->firstOrFail();
        $tlRole = Role::where('slug', Role::TEAM_LEAD)->firstOrFail();
        $empRole = Role::where('slug', Role::EMPLOYEE)->firstOrFail();

        $this->superAdmin = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id' => $superAdminRole->id,
            'is_attendance_applicable' => false,
        ]);

        $this->director = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->dept->id,
            'role_id' => $managerRole->id,
            'is_attendance_applicable' => true,
        ]);

        $this->hr = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id' => $hrRole->id,
            'reports_to_id' => $this->director->id,
            'is_attendance_applicable' => true,
        ]);

        $this->manager = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->dept->id,
            'role_id' => $managerRole->id,
            'reports_to_id' => $this->hr->id,
            'is_attendance_applicable' => true,
        ]);

        $this->teamLead = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->dept->id,
            'role_id' => $tlRole->id,
            'reports_to_id' => $this->manager->id,
            'is_attendance_applicable' => true,
        ]);

        $this->employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->dept->id,
            'role_id' => $empRole->id,
            'reports_to_id' => $this->teamLead->id,
            'is_attendance_applicable' => true,
        ]);

        $this->leaveType = LeaveType::create([
            'company_id' => $this->company->id,
            'name' => 'Paid Time Off',
            'code' => 'PTO',
            'days_per_year' => 20,
            'requires_approval' => true,
            'is_active' => true,
        ]);
    }

    public function test_super_admin_cannot_clock_in_or_clock_out(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $resIn = $this->postJson('/api/v1/attendance/clock-in', [
            'latitude' => 12.9716,
            'longitude' => 77.5946,
        ]);
        $resIn->assertStatus(403);

        $resOut = $this->postJson('/api/v1/attendance/clock-out');
        $resOut->assertStatus(403);
    }

    public function test_super_admin_cannot_apply_for_leave(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $res = $this->postJson('/api/v1/leaves/requests', [
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'reason' => 'Vacation',
        ]);

        $res->assertStatus(403);
    }

    public function test_super_admin_excluded_from_attendance_records(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $res = $this->getJson('/api/v1/attendance/records');
        $res->assertOk();

        // Ensure records do not include any records from non-applicable users
        $data = $res->json('data');
        foreach ($data as $record) {
            $this->assertNotEquals($this->superAdmin->id, $record['user']['id'] ?? null);
        }
    }

    public function test_hr_can_clock_in_and_apply_leave(): void
    {
        Sanctum::actingAs($this->hr);

        $res = $this->getJson('/api/v1/attendance/today');
        $res->assertOk();
        $this->assertTrue($res->json('data.is_applicable'));

        $clockIn = $this->postJson('/api/v1/attendance/clock-in', [
            'latitude' => 12.9716,
            'longitude' => 77.5946,
        ]);
        $clockIn->assertStatus(201);
    }

    public function test_approver_hierarchy_for_leave_requests(): void
    {
        // 1. Employee -> Team Lead
        Sanctum::actingAs($this->employee);
        $res1 = $this->postJson('/api/v1/leaves/requests', [
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'reason' => 'Personal work',
        ]);
        $res1->assertStatus(201);
        $leaveId1 = $res1->json('data.id');
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveId1,
            'approver_id' => $this->teamLead->id,
        ]);

        // 2. Team Lead -> Manager
        Sanctum::actingAs($this->teamLead);
        $res2 = $this->postJson('/api/v1/leaves/requests', [
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'reason' => 'Family event',
        ]);
        $res2->assertStatus(201);
        $leaveId2 = $res2->json('data.id');
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveId2,
            'approver_id' => $this->manager->id,
        ]);

        // 3. Manager -> HR
        Sanctum::actingAs($this->manager);
        $res3 = $this->postJson('/api/v1/leaves/requests', [
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDays(6)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'reason' => 'Medical leave',
        ]);
        $res3->assertStatus(201);
        $leaveId3 = $res3->json('data.id');
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveId3,
            'approver_id' => $this->hr->id,
        ]);

        // 4. HR -> Director / Senior Manager
        Sanctum::actingAs($this->hr);
        $res4 = $this->postJson('/api/v1/leaves/requests', [
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDays(8)->toDateString(),
            'end_date' => now()->addDays(9)->toDateString(),
            'reason' => 'Annual leave',
        ]);
        $res4->assertStatus(201);
        $leaveId4 = $res4->json('data.id');
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveId4,
            'approver_id' => $this->director->id,
            'final_approver' => true,
        ]);
    }

    public function test_user_cannot_approve_own_leave(): void
    {
        // Create a leave request where requester is the HR or Manager
        Sanctum::actingAs($this->hr);
        $res = $this->postJson('/api/v1/leaves/requests', [
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'reason' => 'Rest',
        ]);
        $res->assertStatus(201);
        $leaveId = $res->json('data.id');

        // Requester tries to approve own leave
        $approveRes = $this->putJson("/api/v1/leaves/requests/{$leaveId}/approve");
        $approveRes->assertStatus(403);
    }

    public function test_holiday_management_restricted_to_super_admin_and_hr(): void
    {
        // 1. Employee cannot create holiday
        Sanctum::actingAs($this->employee);
        $this->postJson('/api/v1/holidays', [
            'name' => 'New Year',
            'date' => '2027-01-01',
        ])->assertStatus(403);

        // 2. Manager cannot create holiday
        Sanctum::actingAs($this->manager);
        $this->postJson('/api/v1/holidays', [
            'name' => 'New Year',
            'date' => '2027-01-01',
            'holiday_type' => 'national',
        ])->assertStatus(403);

        // 3. HR can create holiday
        Sanctum::actingAs($this->hr);
        $hrHolidayRes = $this->postJson('/api/v1/holidays', [
            'name' => 'HR Special Day',
            'date' => '2027-05-01',
            'holiday_type' => 'company',
        ]);
        $hrHolidayRes->assertStatus(201);
        $holidayId = $hrHolidayRes->json('data.id');

        // 4. Super Admin can update holiday
        Sanctum::actingAs($this->superAdmin);
        $this->putJson("/api/v1/holidays/{$holidayId}", [
            'name' => 'Company Festival Day',
            'date' => '2027-05-01',
            'holiday_type' => 'company',
        ])->assertOk();

        // 5. Manager cannot delete holiday
        Sanctum::actingAs($this->manager);
        $this->deleteJson("/api/v1/holidays/{$holidayId}")->assertStatus(403);

        // 6. Super Admin can delete holiday
        Sanctum::actingAs($this->superAdmin);
        $this->deleteJson("/api/v1/holidays/{$holidayId}")->assertOk();
    }

    public function test_document_verification_endpoint(): void
    {
        $doc = EmployeeDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->employee->id,
            'uploaded_by_id' => $this->employee->id,
            'title' => 'Degree Certificate',
            'document_type' => 'degree_certificate',
            'file_path' => 'documents/degree.pdf',
            'file_name' => 'degree.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
            'is_verified' => false,
        ]);

        // Employee cannot verify
        Sanctum::actingAs($this->employee);
        $this->putJson("/api/v1/documents/{$doc->id}/verify", [
            'is_verified' => true,
        ])->assertStatus(403);

        // HR can verify
        Sanctum::actingAs($this->hr);
        $verifyRes = $this->putJson("/api/v1/documents/{$doc->id}/verify", [
            'is_verified' => true,
        ]);
        $verifyRes->assertOk()
            ->assertJsonPath('data.is_verified', true);

        $doc->refresh();
        $this->assertTrue($doc->is_verified);
        $this->assertEquals($this->hr->id, $doc->verified_by_id);
        $this->assertNotNull($doc->verified_at);
    }

    public function test_employee_history_is_read_only_via_api(): void
    {
        Sanctum::actingAs($this->hr);

        $res = $this->postJson("/api/v1/employees/{$this->employee->id}/history", [
            'event_type' => 'promotion',
            'title' => 'Manual promotion',
            'effective_date' => now()->toDateString(),
        ]);

        $res->assertStatus(403);
    }
}
