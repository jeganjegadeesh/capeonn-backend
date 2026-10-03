<?php

namespace Tests\Feature\Tasks;

use App\Models\Company;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $department;
    private User $admin;
    private User $manager;
    private User $teamLead;
    private User $employeeMember;
    private User $employeeNonMember;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Capeonn Technologies', 'code' => 'CAP']);
        $this->department = Department::create(['company_id' => $this->company->id, 'name' => 'Engineering', 'code' => 'ENG']);

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
            'department_id' => $this->department->id,
            'role_id' => $mgrRole->id,
        ]);

        $this->teamLead = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $tlRole->id,
            'reports_to_id' => $this->manager->id,
        ]);

        $this->employeeMember = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
            'reports_to_id' => $this->teamLead->id,
        ]);

        $this->employeeNonMember = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
        ]);

        $this->project = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'manager_id' => $this->manager->id,
            'team_lead_id' => $this->teamLead->id,
            'created_by_id' => $this->manager->id,
            'name' => 'Core Platform Migration',
            'code' => 'CPM-101',
            'status' => Project::STATUS_ACTIVE,
            'priority' => Project::PRIORITY_HIGH,
        ]);

        $this->project->members()->attach($this->employeeMember->id, [
            'project_role' => 'Developer',
        ]);
    }

    public function test_manager_and_team_lead_can_create_task_in_active_project(): void
    {
        Sanctum::actingAs($this->teamLead);

        $res = $this->postJson("/api/v1/projects/{$this->project->id}/tasks", [
            'title' => 'Design API schemas',
            'description' => 'Draft JSON:API resource contracts',
            'priority' => 'high',
            'estimated_hours' => 12.5,
            'assigned_to_id' => $this->employeeMember->id,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.title', 'Design API schemas')
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.estimated_hours', 12.5)
            ->assertJsonPath('data.assigned_to.id', $this->employeeMember->id);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Design API schemas',
            'project_id' => $this->project->id,
            'assigned_to_id' => $this->employeeMember->id,
        ]);
    }

    public function test_task_creation_fails_if_project_does_not_accept_work(): void
    {
        $this->project->update(['status' => Project::STATUS_ON_HOLD]);

        Sanctum::actingAs($this->manager);

        $res = $this->postJson("/api/v1/projects/{$this->project->id}/tasks", [
            'title' => 'Write migrations',
        ]);

        $res->assertStatus(422)
            ->assertJsonFragment(['message' => 'Project status [on_hold] does not accept new tasks or modifications.']);
    }

    public function test_cannot_assign_task_to_user_who_is_not_project_member_or_lead(): void
    {
        Sanctum::actingAs($this->teamLead);

        $res = $this->postJson("/api/v1/projects/{$this->project->id}/tasks", [
            'title' => 'Security review',
            'assigned_to_id' => $this->employeeNonMember->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_to_id']);
    }

    public function test_can_assign_task_to_team_lead_or_active_member(): void
    {
        Sanctum::actingAs($this->manager);

        // Assign to team lead
        $resLead = $this->postJson("/api/v1/projects/{$this->project->id}/tasks", [
            'title' => 'Architecture proposal',
            'assigned_to_id' => $this->teamLead->id,
        ]);
        $resLead->assertStatus(201);

        // Assign to member
        $resMember = $this->postJson("/api/v1/projects/{$this->project->id}/tasks", [
            'title' => 'Unit tests',
            'assigned_to_id' => $this->employeeMember->id,
        ]);
        $resMember->assertStatus(201);
    }

    public function test_task_status_lifecycle_transitions(): void
    {
        $task = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'created_by_id' => $this->teamLead->id,
            'assigned_to_id' => $this->employeeMember->id,
            'title' => 'Implement Auth module',
            'status' => Task::STATUS_ASSIGNED,
        ]);

        Sanctum::actingAs($this->employeeMember);

        // Invalid direct leap from assigned to completed fails
        $invalidRes = $this->postJson("/api/v1/tasks/{$task->id}/status", [
            'status' => Task::STATUS_COMPLETED,
        ]);
        $invalidRes->assertStatus(422);

        // Move assigned -> in_progress
        $res1 = $this->postJson("/api/v1/tasks/{$task->id}/status", [
            'status' => Task::STATUS_IN_PROGRESS,
        ]);
        $res1->assertStatus(200)->assertJsonPath('data.status', 'in_progress');

        // Move in_progress -> review
        $res2 = $this->postJson("/api/v1/tasks/{$task->id}/status", [
            'status' => Task::STATUS_REVIEW,
        ]);
        $res2->assertStatus(200)->assertJsonPath('data.status', 'review');

        // Team Lead moves review -> completed
        Sanctum::actingAs($this->teamLead);
        $res3 = $this->postJson("/api/v1/tasks/{$task->id}/status", [
            'status' => Task::STATUS_COMPLETED,
            'reason' => 'PR merged and verified in staging',
        ]);
        $res3->assertStatus(200)->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_cannot_complete_task_with_incomplete_subtasks(): void
    {
        $parent = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'created_by_id' => $this->teamLead->id,
            'title' => 'Database layer overhaul',
            'status' => Task::STATUS_REVIEW,
        ]);

        $subtask = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'parent_task_id' => $parent->id,
            'created_by_id' => $this->teamLead->id,
            'title' => 'Index optimization subtask',
            'status' => Task::STATUS_IN_PROGRESS,
        ]);

        Sanctum::actingAs($this->teamLead);

        $res = $this->postJson("/api/v1/tasks/{$parent->id}/status", [
            'status' => Task::STATUS_COMPLETED,
        ]);

        $res->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot complete task: 1 subtask(s) are still incomplete.']);

        // Now complete the subtask
        $subtask->update(['status' => Task::STATUS_COMPLETED]);

        // Retrying parent completion now succeeds
        $resSuccess = $this->postJson("/api/v1/tasks/{$parent->id}/status", [
            'status' => Task::STATUS_COMPLETED,
        ]);
        $resSuccess->assertStatus(200)->assertJsonPath('data.status', 'completed');
    }

    public function test_dynamic_project_progress_calculation(): void
    {
        Sanctum::actingAs($this->manager);

        // 1. Project with 0 tasks -> progress is null, metrics available is false
        $res0 = $this->getJson("/api/v1/projects/{$this->project->id}");
        $res0->assertStatus(200)
            ->assertJsonPath('data.progress', null)
            ->assertJsonPath('data.task_metrics_available', false)
            ->assertJsonPath('data.task_metrics.total_tasks', 0);

        // 2. Add 2 tasks (1 completed, 1 in_progress) -> progress = 50%
        $task1 = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'created_by_id' => $this->manager->id,
            'title' => 'Task A',
            'status' => Task::STATUS_COMPLETED,
        ]);

        $task2 = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'created_by_id' => $this->manager->id,
            'title' => 'Task B',
            'status' => Task::STATUS_IN_PROGRESS,
        ]);

        $res50 = $this->getJson("/api/v1/projects/{$this->project->id}");
        $res50->assertStatus(200)
            ->assertJsonPath('data.progress', 50)
            ->assertJsonPath('data.task_metrics_available', true)
            ->assertJsonPath('data.task_metrics.total_tasks', 2)
            ->assertJsonPath('data.task_metrics.completed_tasks', 1);

        // 3. Mark task2 completed -> progress = 100%
        $task2->update(['status' => Task::STATUS_COMPLETED]);

        $res100 = $this->getJson("/api/v1/projects/{$this->project->id}");
        $res100->assertStatus(200)
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.task_metrics.completed_tasks', 2);
    }

    public function test_deleting_task_soft_deletes_subtasks_and_logs_activity(): void
    {
        $parent = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'created_by_id' => $this->manager->id,
            'title' => 'Deprecated module',
            'status' => Task::STATUS_BACKLOG,
        ]);

        $subtask = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'parent_task_id' => $parent->id,
            'created_by_id' => $this->manager->id,
            'title' => 'Deprecated subtask',
            'status' => Task::STATUS_BACKLOG,
        ]);

        Sanctum::actingAs($this->manager);

        $res = $this->deleteJson("/api/v1/tasks/{$parent->id}");
        $res->assertStatus(200);

        $this->assertSoftDeleted('tasks', ['id' => $parent->id]);
        $this->assertSoftDeleted('tasks', ['id' => $subtask->id]);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'task_id' => $parent->id,
            'action' => 'task_deleted',
        ]);
    }

    public function test_project_activity_audit_trail_captures_task_id_and_changes(): void
    {
        Sanctum::actingAs($this->teamLead);

        // 1. Create task
        $resCreate = $this->postJson("/api/v1/projects/{$this->project->id}/tasks", [
            'title' => 'Audit logging verification',
            'assigned_to_id' => $this->employeeMember->id,
        ]);
        $taskId = $resCreate->json('data.id');

        // Check created & assigned activities
        $activity = ProjectActivity::where('task_id', $taskId)->where('action', 'task_created')->first();
        $this->assertNotNull($activity);
        $this->assertEquals($this->teamLead->id, $activity->user_id);

        // 2. Status update
        $this->postJson("/api/v1/tasks/{$taskId}/status", [
            'status' => Task::STATUS_IN_PROGRESS,
            'reason' => 'Commencing development',
        ])->assertStatus(200);

        $statusActivity = ProjectActivity::where('task_id', $taskId)->where('action', 'task_status_changed')->first();
        $this->assertNotNull($statusActivity);
        $this->assertEquals('assigned', $statusActivity->old_value);
        $this->assertEquals('in_progress', $statusActivity->new_value);
        $this->assertEquals('Commencing development', $statusActivity->reason);
    }
}
