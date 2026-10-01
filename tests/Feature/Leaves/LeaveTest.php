<?php

namespace Tests\Feature\Leaves;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\OrganizationTestCase;

class LeaveTest extends OrganizationTestCase
{
    private LeaveType $casualLeave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casualLeave = LeaveType::create([
            'company_id'  => $this->company->id,
            'name'        => 'Casual Leave',
            'code'        => 'CL',
            'annual_days' => 12,
            'is_paid'     => true,
            'is_active'   => true,
        ]);
    }

    private function as(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_can_view_leave_balances(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $this->as($emp);

        $res = $this->getJson('/api/v1/leaves/balances');
        $res->assertOk()
            ->assertJsonPath('data.0.code', 'CL')
            ->assertJsonPath('data.0.total_days', 12)
            ->assertJsonPath('data.0.remaining_days', 12);
    }

    public function test_apply_leave_and_balance_update(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $this->as($emp);

        $start = now()->addDays(2)->toDateString();
        $end   = now()->addDays(3)->toDateString(); // 2 days

        $res = $this->postJson('/api/v1/leaves/requests', [
            'leave_type_id' => $this->casualLeave->id,
            'start_date'    => $start,
            'end_date'      => $end,
            'reason'        => 'Family function',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.days_count', 2);

        $balance = LeaveBalance::where('user_id', $emp->id)->where('leave_type_id', $this->casualLeave->id)->first();
        $this->assertEquals(2.0, (float) $balance->pending_days);
        $this->assertEquals(10.0, (float) $balance->remainingDays());
    }

    public function test_apply_leave_insufficient_balance_rejected(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $this->as($emp);

        $start = now()->addDays(2)->toDateString();
        $end   = now()->addDays(20)->toDateString(); // 19 days, exceeds 12

        $res = $this->postJson('/api/v1/leaves/requests', [
            'leave_type_id' => $this->casualLeave->id,
            'start_date'    => $start,
            'end_date'      => $end,
            'reason'        => 'Too long vacation',
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient leave balance. Remaining: 12.0, Requested: 19.0');
    }

    public function test_manager_approve_leave(): void
    {
        $dept = $this->makeDepartment();
        $mgr = $this->makeUser(Role::MANAGER, ['department_id' => $dept->id]);
        $emp = $this->makeUser(Role::EMPLOYEE, ['department_id' => $dept->id, 'reports_to_id' => $mgr->id]);

        $this->as($emp);
        $start = now()->addDays(2)->toDateString();

        $applyRes = $this->postJson('/api/v1/leaves/requests', [
            'leave_type_id' => $this->casualLeave->id,
            'start_date'    => $start,
            'end_date'      => $start,
            'reason'        => 'Personal work',
        ]);

        $leaveId = $applyRes->json('data.id');

        // Manager approves
        $this->as($mgr);
        $approveRes = $this->putJson("/api/v1/leaves/requests/{$leaveId}/approve", [
            'remarks' => 'Approved, have a good time',
        ]);

        $approveRes->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $balance = LeaveBalance::where('user_id', $emp->id)->where('leave_type_id', $this->casualLeave->id)->first();
        $this->assertEquals(0.0, (float) $balance->pending_days);
        $this->assertEquals(1.0, (float) $balance->used_days);
        $this->assertEquals(11.0, (float) $balance->remainingDays());
    }
}
