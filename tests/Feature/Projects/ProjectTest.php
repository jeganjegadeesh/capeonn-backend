<?php

namespace Tests\Feature\Projects;

use App\Models\Company;
use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_status_lifecycle_and_accepts_work_rules(): void
    {
        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Accepts Work Project',
            'code' => 'PRJ-WORK-1',
            'team_lead_id' => $this->teamLead->id,
            'status' => Project::STATUS_PLANNED,
        ]);

        // Planned and Active accept work
        $this->assertTrue($proj->acceptsWork());
        $this->assertTrue($proj->canAcceptTasks());
        $this->assertTrue($proj->canAcceptTimeEntries());

        $proj->status = Project::STATUS_ACTIVE;
        $this->assertTrue($proj->acceptsWork());

        // On Hold, Completed, Archived, and Cancelled do NOT accept work
        foreach ([Project::STATUS_ON_HOLD, Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED, Project::STATUS_CANCELLED] as $blockedStatus) {
            $proj->status = $blockedStatus;
            $this->assertFalse($proj->acceptsWork(), "Status {$blockedStatus} should not accept work");
            $this->assertFalse($proj->canAcceptTasks());
            $this->assertFalse($proj->canAcceptTimeEntries());
        }

        // Check API resource returns accepts_work and is_archived
        $proj->status = Project::STATUS_ARCHIVED;
        $proj->save();

        Sanctum::actingAs($this->admin);
        $res = $this->getJson("/api/v1/projects/{$proj->id}");
        $res->assertOk()
            ->assertJsonPath('data.accepts_work', false)
            ->assertJsonPath('data.is_archived', true);

        // Archived projects are read-only: update and lead assignment return 422
        $this->putJson("/api/v1/projects/{$proj->id}", [
            'name' => 'Attempted Edit of Archived',
            'department_id' => $this->deptEng->id,
        ])->assertStatus(422);

        $this->postJson("/api/v1/projects/{$proj->id}/lead", [
            'team_lead_id' => $this->teamLead->id,
        ])->assertStatus(422);
    }

    public function test_manual_progress_column_removed_and_task_metrics_placeholder(): void
    {
        // 1. Database table must NOT have stored progress column
        $this->assertFalse(Schema::hasColumn('projects', 'progress'));

        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Progress Placeholder Project',
            'code' => 'PRJ-PROG-1',
            'status' => Project::STATUS_PLANNED,
        ]);

        Sanctum::actingAs($this->admin);
        $res = $this->getJson("/api/v1/projects/{$proj->id}");
        $res->assertOk()
            ->assertJsonPath('data.progress', null)
            ->assertJsonPath('data.task_metrics_available', false);
    }

    public function test_team_lead_permissions_and_validation(): void
    {
        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'TL Permissions Project',
            'code' => 'PRJ-TL-PERM',
            'team_lead_id' => $this->teamLead->id,
            'status' => Project::STATUS_ACTIVE,
        ]);

        // Team Lead can add and remove members on own project using projects.team
        Sanctum::actingAs($this->teamLead);
        $addRes = $this->postJson("/api/v1/projects/{$proj->id}/members", [
            'user_id' => $this->employee->id,
            'project_role' => 'Developer',
        ]);
        $addRes->assertCreated();

        // Team Lead cannot assign Team Lead (projects.assign is manager/admin only)
        $leadRes = $this->postJson("/api/v1/projects/{$proj->id}/lead", [
            'team_lead_id' => $this->teamLead->id,
        ]);
        $leadRes->assertStatus(403);

        // Team Lead is counted in total_team_count (1 member + 1 lead = 2)
        $detailRes = $this->getJson("/api/v1/projects/{$proj->id}");
        $detailRes->assertOk()
            ->assertJsonPath('data.members_count', 1)
            ->assertJsonPath('data.total_team_count', 2);

        // Validation rejects assigning a non-team-lead user as lead
        Sanctum::actingAs($this->manager);
        $invalidLeadRes = $this->postJson("/api/v1/projects/{$proj->id}/lead", [
            'team_lead_id' => $this->employee->id,
        ]);
        $invalidLeadRes->assertStatus(422)->assertJsonValidationErrors('team_lead_id');
    }

    public function test_manager_visibility_by_membership_across_departments_and_task_assignment(): void
    {
        // Project in Design department, managed by another manager
        $otherManager = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptDesign->id,
            'role_id' => Role::where('slug', Role::MANAGER)->first()->id,
        ]);

        $designProj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptDesign->id,
            'name' => 'Design Overhaul Project',
            'code' => 'PRJ-DES-1',
            'status' => Project::STATUS_ACTIVE,
        ]);

        // Engineering manager initially cannot see this Design project
        Sanctum::actingAs($this->manager);
        $this->getJson("/api/v1/projects/{$designProj->id}")->assertStatus(403);

        // Add Engineering manager as a member of the Design project
        $designProj->members()->attach($this->manager->id, ['project_role' => 'Technical Advisor']);

        // Now Engineering manager CAN view the project (membership gives access)
        $res = $this->getJson("/api/v1/projects/{$designProj->id}");
        $res->assertOk()->assertJsonPath('data.name', 'Design Overhaul Project');

        $listRes = $this->getJson('/api/v1/projects');
        $listRes->assertOk();
        $ids = collect($listRes->json('data'))->pluck('id')->all();
        $this->assertContains($designProj->id, $ids);

        // Task assignment verification: only members and team lead can be assigned tasks
        $this->assertTrue($designProj->canAssignTaskTo($this->manager));
        $this->assertFalse($designProj->canAssignTaskTo($this->employee)); // Employee is not on this project
    }

    public function test_audit_log_structure_with_task_id_and_values(): void
    {
        $this->assertTrue(Schema::hasColumn('project_activities', 'field'));
        $this->assertTrue(Schema::hasColumn('project_activities', 'old_value'));
        $this->assertTrue(Schema::hasColumn('project_activities', 'new_value'));
        $this->assertTrue(Schema::hasColumn('project_activities', 'reason'));
        $this->assertTrue(Schema::hasColumn('project_activities', 'task_id'));

        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Audit Structure Project',
            'code' => 'PRJ-AUDIT-2',
            'status' => Project::STATUS_ACTIVE,
        ]);

        $task = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $proj->id,
            'title' => 'Initial Task',
            'status' => 'backlog',
        ]);

        $activity = $proj->recordActivity(
            action: 'task_status_changed',
            description: 'Task status updated to in_progress',
            userId: $this->admin->id,
            field: 'status',
            oldValue: 'backlog',
            newValue: 'in_progress',
            reason: 'Work started by developer',
            taskId: $task->id
        );

        $this->assertDatabaseHas('project_activities', [
            'id' => $activity->id,
            'project_id' => $proj->id,
            'task_id' => $task->id,
            'action' => 'task_status_changed',
            'field' => 'status',
            'old_value' => 'backlog',
            'new_value' => 'in_progress',
            'reason' => 'Work started by developer',
        ]);
    }

    public function test_tasks_table_reserves_project_id_and_prevents_deletion_with_tasks(): void
    {
        $this->assertTrue(Schema::hasTable('tasks'));
        $this->assertTrue(Schema::hasColumn('tasks', 'project_id'));

        $proj = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->deptEng->id,
            'name' => 'Deletable Project Check',
            'code' => 'PRJ-DEL-CHK',
            'status' => Project::STATUS_PLANNED,
        ]);

        // Create a task under this project
        $task = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $proj->id,
            'title' => 'Sample Phase 5 Task',
            'status' => 'backlog',
        ]);

        $this->assertEquals($proj->id, $task->project->id);
        $this->assertCount(1, $proj->tasks);

        // Admin cannot delete a project that has tasks even if it has no members
        Sanctum::actingAs($this->admin);
        $delRes = $this->deleteJson("/api/v1/projects/{$proj->id}");
        $delRes->assertStatus(422)
            ->assertJsonPath('message', 'Only empty projects with no members or tasks can be deleted. Please archive this project instead.');

        // Deleting the task allows admin to delete the empty project
        $task->forceDelete();
        $this->deleteJson("/api/v1/projects/{$proj->id}")->assertOk();
        $this->assertSoftDeleted('projects', ['id' => $proj->id]);
    }
}
