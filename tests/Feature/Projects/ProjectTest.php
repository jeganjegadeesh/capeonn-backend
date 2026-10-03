<?php

namespace Tests\Feature\Projects;

use App\Models\Company;
use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $deptEng;
    private Department $deptDesign;
    private User $admin;
    private User $manager;
    private User $teamLead;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Capeonn Technologies', 'code' => 'CAP']);
        $this->deptEng = Department::create(['company_id' => $this->company->id, 'name' => 'Engineering', 'code' => 'ENG']);
        $this->deptDesign = Department::create(['company_id' => $this->company->id, 'name' => 'Design', 'code' => 'DES']);

        $adminRole = Role::where('slug', 'admin')->firstOrFail();
        $mgrRole = Role::where('slug', Role::MANAGER)->firstOrFail();
        $tlRole = Role::where('slug', Role::TEAM_LEAD)->firstOrFail();
        $empRole = Role::where('slug', Role::EMPLOYEE)->firstOrFail();

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id' => $adminRole->id,
        ]);

        $this->manager = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'role_id' => $mgrRole->id,
        ]);

        $this->teamLead = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'role_id' => $tlRole->id,
            'reports_to_id' => $this->manager->id,
        ]);

        $this->employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'role_id' => $empRole->id,
            'reports_to_id' => $this->teamLead->id,
        ]);
    }

    public function test_admin_can_create_project_and_it_records_activity(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Mobile App Overhaul',
            'code' => 'PRJ-MOB',
            'department_id' => $this->deptEng->id,
            'client_name' => 'Acme Corp',
            'description' => 'Rebuilding the mobile app with Flutter',
            'status' => 'in_progress',
            'priority' => 'high',
            'team_lead_id' => $this->teamLead->id,
            'start_date' => Carbon::today()->toDateString(),
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
            'estimated_hours' => 160.5,
            'budget' => 25000.00,
            'members' => [
                ['user_id' => $this->employee->id, 'project_role' => 'Flutter Developer'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Mobile App Overhaul')
            ->assertJsonPath('data.code', 'PRJ-MOB')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.team_lead.id', $this->teamLead->id);

        $this->assertDatabaseHas('projects', [
            'name' => 'Mobile App Overhaul',
            'code' => 'PRJ-MOB',
            'team_lead_id' => $this->teamLead->id,
        ]);

        $this->assertDatabaseHas('project_members', [
            'user_id' => $this->employee->id,
            'project_role' => 'Flutter Developer',
        ]);

        $this->assertDatabaseHas('project_activities', [
            'action' => 'created',
        ]);
    }

    public function test_manager_can_create_project_in_own_department_only(): void
    {
        Sanctum::actingAs($this->manager);

        // Success in own department
        $ok = $this->postJson('/api/v1/projects', [
            'name' => 'Backend API Modernization',
            'department_id' => $this->deptEng->id,
            'status' => 'planning',
        ]);
        $ok->assertCreated();

        // 403 in other department
        $forbidden = $this->postJson('/api/v1/projects', [
            'name' => 'Brand Redesign',
            'department_id' => $this->deptDesign->id,
        ]);
        $forbidden->assertStatus(403);
    }

    public function test_team_lead_and_employee_cannot_create_projects(): void
    {
        Sanctum::actingAs($this->teamLead);
        $this->postJson('/api/v1/projects', [
            'name' => 'Unauthorized Project',
            'department_id' => $this->deptEng->id,
        ])->assertStatus(403);

        Sanctum::actingAs($this->employee);
        $this->postJson('/api/v1/projects', [
            'name' => 'Unauthorized Project',
            'department_id' => $this->deptEng->id,
        ])->assertStatus(403);
    }

    public function test_team_lead_sees_only_assigned_projects(): void
    {
        // Project 1: Assigned to Team Lead
        $proj1 = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Lead Project',
            'code' => 'PRJ-001',
            'team_lead_id' => $this->teamLead->id,
            'status' => 'in_progress',
        ]);

        // Project 2: Not assigned to Team Lead
        $proj2 = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Other Project',
            'code' => 'PRJ-002',
            'status' => 'planning',
        ]);

        Sanctum::actingAs($this->teamLead);

        $res = $this->getJson('/api/v1/projects');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($proj1->id, $ids);
        $this->assertNotContains($proj2->id, $ids);

        // Show endpoint
        $this->getJson("/api/v1/projects/{$proj1->id}")->assertOk();
        $this->getJson("/api/v1/projects/{$proj2->id}")->assertStatus(403);
    }

    public function test_employee_sees_only_projects_where_member(): void
    {
        $proj1 = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Member Project',
            'code' => 'PRJ-003',
        ]);
        $proj1->members()->attach($this->employee->id, ['project_role' => 'QA']);

        $proj2 = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Unassigned Project',
            'code' => 'PRJ-004',
        ]);

        Sanctum::actingAs($this->employee);

        $res = $this->getJson('/api/v1/projects');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($proj1->id, $ids);
        $this->assertNotContains($proj2->id, $ids);
    }

    public function test_assign_lead_and_manage_members(): void
    {
        Sanctum::actingAs($this->manager);

        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Core Architecture',
            'code' => 'PRJ-CORE',
        ]);

        // Assign Lead
        $leadRes = $this->postJson("/api/v1/projects/{$proj->id}/lead", [
            'team_lead_id' => $this->teamLead->id,
        ]);
        $leadRes->assertOk()->assertJsonPath('data.team_lead.id', $this->teamLead->id);

        $this->assertDatabaseHas('projects', [
            'id' => $proj->id,
            'team_lead_id' => $this->teamLead->id,
        ]);

        // Add Member
        $addRes = $this->postJson("/api/v1/projects/{$proj->id}/members", [
            'user_id' => $this->employee->id,
            'project_role' => 'Backend Engineer',
        ]);
        $addRes->assertCreated();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $proj->id,
            'user_id' => $this->employee->id,
            'project_role' => 'Backend Engineer',
        ]);

        // Update Member Role
        $updRes = $this->putJson("/api/v1/projects/{$proj->id}/members/{$this->employee->id}", [
            'project_role' => 'Senior Backend Engineer',
        ]);
        $updRes->assertOk();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $proj->id,
            'user_id' => $this->employee->id,
            'project_role' => 'Senior Backend Engineer',
        ]);

        // Remove Member
        $delRes = $this->deleteJson("/api/v1/projects/{$proj->id}/members/{$this->employee->id}");
        $delRes->assertOk();

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $proj->id,
            'user_id' => $this->employee->id,
        ]);

        // Check activities logged
        $actRes = $this->getJson("/api/v1/projects/{$proj->id}/activities");
        $actRes->assertOk();
        $actions = collect($actRes->json('data'))->pluck('action')->all();

        $this->assertContains('lead_assigned', $actions);
        $this->assertContains('member_added', $actions);
        $this->assertContains('member_updated', $actions);
        $this->assertContains('member_removed', $actions);
    }

    public function test_dashboard_metrics_and_overdue_detection(): void
    {
        Sanctum::actingAs($this->admin);

        // Project 1: in progress, overdue
        Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Overdue Task System',
            'code' => 'PRJ-OD',
            'status' => 'in_progress',
            'priority' => 'urgent',
            'deadline' => Carbon::yesterday()->toDateString(),
        ]);

        // Project 2: completed (should not count as overdue even if deadline past)
        Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Finished Feature',
            'code' => 'PRJ-FIN',
            'status' => 'completed',
            'priority' => 'low',
            'deadline' => Carbon::yesterday()->toDateString(),
        ]);

        // Project 3: planning, due next week
        Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Upcoming Portal',
            'code' => 'PRJ-UP',
            'status' => 'planning',
            'priority' => 'medium',
            'deadline' => Carbon::today()->addDays(7)->toDateString(),
        ]);

        $res = $this->getJson('/api/v1/projects/dashboard');
        $res->assertOk()
            ->assertJsonPath('data.total_projects', 3)
            ->assertJsonPath('data.in_progress_count', 1)
            ->assertJsonPath('data.completed_count', 1)
            ->assertJsonPath('data.planning_count', 1)
            ->assertJsonPath('data.overdue_count', 1)
            ->assertJsonPath('data.priority_breakdown.urgent', 1)
            ->assertJsonPath('data.priority_breakdown.low', 1);
    }

    public function test_team_lead_can_manage_team_members_on_own_project(): void
    {
        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'TL Led Project',
            'code' => 'PRJ-TL-1',
            'team_lead_id' => $this->teamLead->id,
            'status' => Project::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($this->teamLead);

        // Add member
        $resAdd = $this->postJson("/api/v1/projects/{$proj->id}/members", [
            'user_id' => $this->employee->id,
            'project_role' => 'Developer',
        ]);
        $resAdd->assertCreated();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $proj->id,
            'user_id' => $this->employee->id,
        ]);

        // Remove member
        $resDel = $this->deleteJson("/api/v1/projects/{$proj->id}/members/{$this->employee->id}");
        $resDel->assertOk();

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $proj->id,
            'user_id' => $this->employee->id,
        ]);
    }

    public function test_team_lead_cannot_manage_members_on_other_projects(): void
    {
        $otherLead = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'role_id' => Role::where('slug', Role::TEAM_LEAD)->first()->id,
        ]);

        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Other TL Project',
            'code' => 'PRJ-TL-2',
            'team_lead_id' => $otherLead->id,
            'status' => Project::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($this->teamLead);

        $this->postJson("/api/v1/projects/{$proj->id}/members", [
            'user_id' => $this->employee->id,
            'project_role' => 'Developer',
        ])->assertStatus(403);
    }

    public function test_cannot_add_members_to_inactive_or_closed_projects(): void
    {
        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'On Hold Project',
            'code' => 'PRJ-HOLD',
            'team_lead_id' => $this->teamLead->id,
            'status' => Project::STATUS_ON_HOLD,
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/projects/{$proj->id}/members", [
            'user_id' => $this->employee->id,
            'project_role' => 'Developer',
        ])->assertStatus(422);
    }

    public function test_hr_cannot_view_projects_unless_assigned(): void
    {
        $hrRole = Role::where('slug', Role::HR)->firstOrFail();
        $hr = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id' => $hrRole->id,
        ]);

        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Confidential Project',
            'code' => 'PRJ-CONF',
            'status' => Project::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($hr);

        // Not assigned -> cannot view
        $this->getJson("/api/v1/projects/{$proj->id}")->assertStatus(403);

        // Assign HR as member
        $proj->members()->attach($hr->id, ['project_role' => 'HR Liaison']);

        // Now accessible
        $this->getJson("/api/v1/projects/{$proj->id}")->assertOk();
    }

    public function test_employees_and_hr_cannot_view_activity_logs(): void
    {
        $hrRole = Role::where('slug', Role::HR)->firstOrFail();
        $hr = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id' => $hrRole->id,
        ]);

        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Audited Project',
            'code' => 'PRJ-AUDIT',
            'team_lead_id' => $this->teamLead->id,
            'status' => Project::STATUS_ACTIVE,
        ]);
        $proj->members()->attach($this->employee->id, ['project_role' => 'Dev']);
        $proj->members()->attach($hr->id, ['project_role' => 'HR']);
        $proj->recordActivity('created', 'Project created', $this->admin->id);

        // Employee blocked from activities endpoint
        Sanctum::actingAs($this->employee);
        $this->getJson("/api/v1/projects/{$proj->id}/activities")->assertStatus(403);

        // HR blocked from activities endpoint
        Sanctum::actingAs($hr);
        $this->getJson("/api/v1/projects/{$proj->id}/activities")->assertStatus(403);

        // Team Lead CAN view activities on own project
        Sanctum::actingAs($this->teamLead);
        $this->getJson("/api/v1/projects/{$proj->id}/activities")->assertOk();
    }

    public function test_status_lifecycle_and_transition_rules(): void
    {
        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Lifecycle Project',
            'code' => 'PRJ-LIFE',
            'team_lead_id' => $this->teamLead->id,
            'status' => Project::STATUS_PLANNED,
        ]);

        Sanctum::actingAs($this->admin);

        // Planned -> Active succeeds
        $resActive = $this->postJson("/api/v1/projects/{$proj->id}/status", [
            'status' => Project::STATUS_ACTIVE,
        ]);
        $resActive->assertOk()->assertJsonPath('data.status', 'active');

        // Active -> On Hold requires reason
        $resNoReason = $this->postJson("/api/v1/projects/{$proj->id}/status", [
            'status' => Project::STATUS_ON_HOLD,
        ]);
        $resNoReason->assertStatus(422)->assertJsonValidationErrors('reason');

        $resWithReason = $this->postJson("/api/v1/projects/{$proj->id}/status", [
            'status' => Project::STATUS_ON_HOLD,
            'reason' => 'Waiting for client specifications',
        ]);
        $resWithReason->assertOk()->assertJsonPath('data.status', 'on_hold');

        // On Hold -> Cancelled requires reason
        $resCancel = $this->postJson("/api/v1/projects/{$proj->id}/status", [
            'status' => Project::STATUS_CANCELLED,
            'reason' => 'Client withdrew budget',
        ]);
        $resCancel->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Cancelled -> Active requires reason (reopening)
        $resReopen = $this->postJson("/api/v1/projects/{$proj->id}/status", [
            'status' => Project::STATUS_ACTIVE,
            'reason' => 'Contract renegotiated',
        ]);
        $resReopen->assertOk()->assertJsonPath('data.status', 'active');

        // Invalid transition: Active -> Planned is not allowed
        $resInvalid = $this->postJson("/api/v1/projects/{$proj->id}/status", [
            'status' => Project::STATUS_PLANNED,
        ]);
        $resInvalid->assertStatus(422);
    }

    public function test_completion_request_and_approval_workflow(): void
    {
        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Deliverable Project',
            'code' => 'PRJ-DELIV',
            'team_lead_id' => $this->teamLead->id,
            'status' => Project::STATUS_ACTIVE,
        ]);

        // Team Lead cannot mark completed directly via /status
        Sanctum::actingAs($this->teamLead);
        $this->postJson("/api/v1/projects/{$proj->id}/status", [
            'status' => Project::STATUS_COMPLETED,
        ])->assertStatus(403);

        // Team Lead requests completion
        $resReq = $this->postJson("/api/v1/projects/{$proj->id}/request-completion", [
            'notes' => 'All milestones delivered and QA tested.',
        ]);
        $resReq->assertOk()
            ->assertJsonPath('data.completion_request_notes', 'All milestones delivered and QA tested.');
        $this->assertNotNull($resReq->json('data.completion_requested_at'));

        // Manager approves completion
        Sanctum::actingAs($this->manager);
        $resApprove = $this->postJson("/api/v1/projects/{$proj->id}/approve-completion");
        $resApprove->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertNull($resApprove->json('data.completion_requested_at'));

        $this->assertDatabaseHas('projects', [
            'id' => $proj->id,
            'status' => Project::STATUS_COMPLETED,
        ]);
    }

    public function test_only_admin_can_delete_empty_project(): void
    {
        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Empty Project',
            'code' => 'PRJ-EMPTY',
            'status' => Project::STATUS_PLANNED,
        ]);

        // Manager cannot delete (must archive instead)
        Sanctum::actingAs($this->manager);
        $this->deleteJson("/api/v1/projects/{$proj->id}")->assertStatus(403);

        // Add member -> Admin cannot delete non-empty project
        $proj->members()->attach($this->employee->id, ['project_role' => 'Dev']);
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/v1/projects/{$proj->id}")->assertStatus(422);

        // Remove member -> Admin can delete empty project
        $proj->members()->detach($this->employee->id);
        $this->deleteJson("/api/v1/projects/{$proj->id}")->assertOk();

        $this->assertSoftDeleted('projects', ['id' => $proj->id]);
    }

    public function test_team_lead_validation_rejects_non_lead_users(): void
    {
        Sanctum::actingAs($this->admin);

        // Assigning an employee as Team Lead must fail validation
        $res = $this->postJson('/api/v1/projects', [
            'name' => 'Invalid Lead Project',
            'department_id' => $this->deptEng->id,
            'team_lead_id' => $this->employee->id,
        ]);
        $res->assertStatus(422)->assertJsonValidationErrors('team_lead_id');
    }

    public function test_reassign_lead_with_keep_as_member_option(): void
    {
        $newLead = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'role_id' => Role::where('slug', Role::TEAM_LEAD)->first()->id,
        ]);

        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Lead Handover Project',
            'code' => 'PRJ-HO',
            'team_lead_id' => $this->teamLead->id,
            'status' => Project::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($this->manager);

        $res = $this->postJson("/api/v1/projects/{$proj->id}/lead", [
            'team_lead_id' => $newLead->id,
            'reason' => 'Original lead rotated to mobile lead',
            'keep_as_member' => true,
        ]);
        $res->assertOk()->assertJsonPath('data.team_lead.id', $newLead->id);

        // Old lead is now in project_members
        $this->assertDatabaseHas('project_members', [
            'project_id' => $proj->id,
            'user_id' => $this->teamLead->id,
        ]);
    }

    public function test_member_availability_warnings_for_leave_and_workload(): void
    {
        // 1. Give employee an approved leave request
        $leaveType = \App\Models\LeaveType::create([
            'company_id' => $this->company->id,
            'name' => 'Annual Leave',
            'code' => 'AL',
            'days_per_year' => 20,
        ]);
        \App\Models\LeaveRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => Carbon::today()->addDays(5)->toDateString(),
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'days_count' => 5,
            'reason' => 'Annual family vacation',
            'status' => 'approved',
        ]);

        // 2. Put employee in 3 existing active projects
        for ($i = 1; $i <= 3; $i++) {
            $p = Project::create([
                'company_id' => $this->company->id,
                'department_id' => $this->deptEng->id,
                'name' => "Workload Project {$i}",
                'code' => "PRJ-WL-{$i}",
                'status' => Project::STATUS_ACTIVE,
            ]);
            $p->members()->attach($this->employee->id, ['project_role' => 'Dev']);
        }

        // 3. Now add employee to a 4th project
        $targetProject = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Target Project',
            'code' => 'PRJ-TGT',
            'start_date' => Carbon::today()->toDateString(),
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
            'status' => Project::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($this->manager);

        $res = $this->postJson("/api/v1/projects/{$targetProject->id}/members", [
            'user_id' => $this->employee->id,
            'project_role' => 'Contributor',
        ]);

        $res->assertCreated();
        $warnings = $res->json('warnings');
        $this->assertNotEmpty($warnings);
        $warningText = implode(' ', $warnings);
        $this->assertStringContainsString('approved leave', $warningText);
        $this->assertStringContainsString('workload threshold', $warningText);
    }

    public function test_project_activity_is_append_only(): void
    {
        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Immutable Audit Project',
            'code' => 'PRJ-IMM',
            'status' => Project::STATUS_PLANNED,
        ]);

        $activity = $proj->recordActivity('created', 'Initial activity', $this->admin->id);

        $this->expectException(\RuntimeException::class);
        $activity->update(['description' => 'Tampered activity']);
    }
}
