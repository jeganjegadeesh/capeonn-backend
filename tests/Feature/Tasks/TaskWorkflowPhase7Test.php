<?php

namespace Tests\Feature\Tasks;

use App\Events\Tasks\TaskChangesRequestedEvent;
use App\Events\Tasks\TaskCommentCreatedEvent;
use App\Events\Tasks\TaskCompletedEvent;
use App\Events\Tasks\TaskReopenedEvent;
use App\Events\Tasks\TaskSubmittedForReviewEvent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TimeEntry;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskWorkflowPhase7Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $department;
    private User $admin;
    private User $teamLead;
    private User $employee1;
    private User $employee2;
    private Project $project;
    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Capeonn Ltd', 'code' => 'CPN', 'is_active' => true]);

        $this->department = Department::create([
            'company_id' => $this->company->id,
            'name'       => 'Engineering',
            'code'       => 'ENG',
        ]);

        $adminRole = Role::where('slug', Role::ADMIN)->firstOrFail();
        $tlRole    = Role::where('slug', Role::TEAM_LEAD)->firstOrFail();
        $empRole   = Role::where('slug', Role::EMPLOYEE)->firstOrFail();

        $this->admin = User::factory()->create([
            'company_id'    => $this->company->id,
            'role_id'       => $adminRole->id,
            'name'          => 'Admin User',
            'is_active'     => true,
        ]);

        $this->teamLead = User::factory()->create([
            'company_id'    => $this->company->id,
            'department_id' => $this->department->id,
            'role_id'       => $tlRole->id,
            'name'          => 'Alice Lead',
            'is_active'     => true,
        ]);

        $this->employee1 = User::factory()->create([
            'company_id'    => $this->company->id,
            'department_id' => $this->department->id,
            'role_id'       => $empRole->id,
            'name'          => 'Bob Dev',
            'is_active'     => true,
        ]);

        $this->employee2 = User::factory()->create([
            'company_id'    => $this->company->id,
            'department_id' => $this->department->id,
            'role_id'       => $empRole->id,
            'name'          => 'Charlie Dev',
            'is_active'     => true,
        ]);

        $this->project = Project::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->department->id,
            'name'          => 'Alpha Platform',
            'code'          => 'CPN-ALP',
            'status'        => 'active',
            'priority'      => 'high',
            'start_date'    => now(),
            'deadline'      => now()->addMonth(),
            'team_lead_id'  => $this->teamLead->id,
            'created_by_id' => $this->admin->id,
        ]);

        $this->project->members()->attach($this->employee1->id, ['project_role' => 'Developer', 'assigned_at' => now()]);
        $this->project->members()->attach($this->employee2->id, ['project_role' => 'Developer', 'assigned_at' => now()]);

        $this->task = Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->project->id,
            'title'           => 'Implement Auth Tokens',
            'description'     => 'Build JWT token handling and refresh cycle',
            'status'          => Task::STATUS_IN_PROGRESS,
            'priority'        => 'high',
            'assigned_to_id'  => $this->employee1->id,
            'created_by_id'   => $this->teamLead->id,
            'due_date'        => now()->addWeek(),
            'estimated_hours' => 10,
        ]);
    }

    /**
     * 1. Comment creation and listing.
     */
    public function test_user_can_comment_on_task_and_view_comments(): void
    {
        Event::fake([TaskCommentCreatedEvent::class]);

        Sanctum::actingAs($this->employee1);

        $res = $this->postJson("/api/v1/tasks/{$this->task->id}/comments", [
            'comment'  => 'I have completed the JWT middleware, please review.',
            'mentions' => [$this->teamLead->id],
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.comment', 'I have completed the JWT middleware, please review.');
        $res->assertJsonPath('data.can_edit', true);

        Event::assertDispatched(TaskCommentCreatedEvent::class);

        // Check activity log
        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'task_id'    => $this->task->id,
            'action'     => 'comment_added',
            'user_id'    => $this->employee1->id,
        ]);

        // View comments list
        Sanctum::actingAs($this->teamLead);
        $listRes = $this->getJson("/api/v1/tasks/{$this->task->id}/comments");
        $listRes->assertStatus(200);
        $this->assertCount(1, $listRes->json('data'));
    }

    /**
     * 2. Author can update comment, non-author cannot.
     */
    public function test_author_can_update_comment_non_author_forbidden(): void
    {
        $comment = TaskComment::create([
            'company_id' => $this->company->id,
            'task_id'    => $this->task->id,
            'user_id'    => $this->employee1->id,
            'comment'    => 'Initial draft',
        ]);

        // Non-author attempt
        Sanctum::actingAs($this->employee2);
        $failRes = $this->putJson("/api/v1/tasks/{$this->task->id}/comments/{$comment->id}", [
            'comment' => 'Malicious edit',
        ]);
        $failRes->assertStatus(403);

        // Author attempt
        Sanctum::actingAs($this->employee1);
        $okRes = $this->putJson("/api/v1/tasks/{$this->task->id}/comments/{$comment->id}", [
            'comment' => 'Updated draft with corrections',
        ]);
        $okRes->assertStatus(200);
        $okRes->assertJsonPath('data.comment', 'Updated draft with corrections');
        $okRes->assertJsonPath('data.is_edited', true);
    }

    /**
     * 3. Author and Team Lead can delete comment.
     */
    public function test_author_and_lead_can_delete_comment(): void
    {
        $comment = TaskComment::create([
            'company_id' => $this->company->id,
            'task_id'    => $this->task->id,
            'user_id'    => $this->employee1->id,
            'comment'    => 'To be deleted',
        ]);

        // Team Lead deletes it
        Sanctum::actingAs($this->teamLead);
        $delRes = $this->deleteJson("/api/v1/tasks/{$this->task->id}/comments/{$comment->id}");
        $delRes->assertStatus(200);

        $this->assertSoftDeleted('task_comments', ['id' => $comment->id]);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'task_id'    => $this->task->id,
            'action'     => 'comment_deleted',
        ]);
    }

    /**
     * 4. Assignee submits task for review.
     */
    public function test_assignee_can_submit_task_for_review(): void
    {
        Event::fake([TaskSubmittedForReviewEvent::class]);

        Sanctum::actingAs($this->employee1);

        $res = $this->postJson("/api/v1/tasks/{$this->task->id}/submit-for-review", [
            'notes' => 'All unit tests passing, ready for TL verification.',
        ]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.status', Task::STATUS_REVIEW);

        $this->assertEquals(Task::STATUS_REVIEW, $this->task->fresh()->status);
        Event::assertDispatched(TaskSubmittedForReviewEvent::class);

        $this->assertDatabaseHas('project_activities', [
            'task_id' => $this->task->id,
            'action'  => 'task_submitted_for_review',
        ]);
    }

    /**
     * 5. Self-approval guard: Assignee CANNOT approve their own task.
     */
    public function test_assignee_cannot_approve_their_own_task(): void
    {
        $this->task->update([
            'assigned_to_id' => $this->teamLead->id,
            'status'         => Task::STATUS_REVIEW,
        ]);

        Sanctum::actingAs($this->teamLead);

        $res = $this->postJson("/api/v1/tasks/{$this->task->id}/approve");
        $res->assertStatus(403);
        $res->assertJson(['message' => 'Assignees cannot approve their own work.']);
    }

    /**
     * 6. Team Lead can approve task and stop active timers.
     */
    public function test_team_lead_can_approve_task_and_stop_timer(): void
    {
        Event::fake([TaskCompletedEvent::class]);

        $this->task->update(['status' => Task::STATUS_REVIEW]);

        // Running timer on task
        $timer = TimeEntry::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'task_id'    => $this->task->id,
            'user_id'    => $this->employee1->id,
            'started_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($this->teamLead);

        $res = $this->postJson("/api/v1/tasks/{$this->task->id}/approve");
        $res->assertStatus(200);
        $res->assertJsonPath('data.status', Task::STATUS_COMPLETED);

        $this->assertEquals(Task::STATUS_COMPLETED, $this->task->fresh()->status);
        $this->assertNotNull($this->task->fresh()->completed_at);

        // Timer stopped
        $this->assertNotNull($timer->fresh()->ended_at);

        Event::assertDispatched(TaskCompletedEvent::class);
        $this->assertDatabaseHas('project_activities', [
            'task_id' => $this->task->id,
            'action'  => 'task_completed',
        ]);
    }

    /**
     * 7. Team Lead can request changes with mandatory reason.
     */
    public function test_team_lead_can_request_changes_requiring_reason(): void
    {
        Event::fake([TaskChangesRequestedEvent::class]);

        $this->task->update(['status' => Task::STATUS_REVIEW]);

        Sanctum::actingAs($this->teamLead);

        // Missing reason fails validation
        $failRes = $this->postJson("/api/v1/tasks/{$this->task->id}/request-changes", []);
        $failRes->assertStatus(422);

        // Valid reason succeeds
        $okRes = $this->postJson("/api/v1/tasks/{$this->task->id}/request-changes", [
            'reason' => 'Please add unit tests for token expiration edge cases.',
        ]);
        $okRes->assertStatus(200);
        $okRes->assertJsonPath('data.status', Task::STATUS_CHANGES_REQUIRED);

        Event::assertDispatched(TaskChangesRequestedEvent::class);
        $this->assertDatabaseHas('project_activities', [
            'task_id' => $this->task->id,
            'action'  => 'task_changes_requested',
            'reason'  => 'Please add unit tests for token expiration edge cases.',
        ]);
    }

    /**
     * 8. Reopening completed task requires reason.
     */
    public function test_reopening_completed_task_requires_reason(): void
    {
        Event::fake([TaskReopenedEvent::class]);

        $this->task->update([
            'status'       => Task::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($this->teamLead);

        $failRes = $this->postJson("/api/v1/tasks/{$this->task->id}/reopen", []);
        $failRes->assertStatus(422);

        $okRes = $this->postJson("/api/v1/tasks/{$this->task->id}/reopen", [
            'reason' => 'Regression discovered in staging build.',
        ]);
        $okRes->assertStatus(200);
        $okRes->assertJsonPath('data.status', Task::STATUS_IN_PROGRESS);
        $this->assertNull($this->task->fresh()->completed_at);

        Event::assertDispatched(TaskReopenedEvent::class);
        $this->assertDatabaseHas('project_activities', [
            'task_id' => $this->task->id,
            'action'  => 'task_reopened',
            'reason'  => 'Regression discovered in staging build.',
        ]);
    }

    /**
     * 9. Reassigning task stops previous assignee's running timer.
     */
    public function test_reassigning_task_stops_running_timer(): void
    {
        $timer = TimeEntry::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'task_id'    => $this->task->id,
            'user_id'    => $this->employee1->id,
            'started_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($this->teamLead);

        $res = $this->postJson("/api/v1/tasks/{$this->task->id}/reassign", [
            'assigned_to_id' => $this->employee2->id,
            'reason'         => 'Bob went on sick leave, reassigning to Charlie.',
        ]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.assigned_to_id', $this->employee2->id);

        $this->assertNotNull($timer->fresh()->ended_at);
        $this->assertEquals($this->employee2->id, $this->task->fresh()->assigned_to_id);
    }

    /**
     * 10. Review queue endpoint lists tasks waiting for review.
     */
    public function test_review_queue_returns_tasks_in_review(): void
    {
        $this->task->update(['status' => Task::STATUS_REVIEW]);

        Sanctum::actingAs($this->teamLead);

        $res = $this->getJson('/api/v1/tasks/review-queue');
        $res->assertStatus(200);
        $res->assertJsonPath('meta.total', 1);
        $res->assertJsonPath('data.0.id', $this->task->id);
        $res->assertJsonPath('data.0.status', Task::STATUS_REVIEW);
    }

    /**
     * 11. Task activities endpoint returns audit records for task.
     */
    public function test_task_activities_endpoint_returns_filtered_records(): void
    {
        $this->project->recordActivity(
            action: 'task_submitted_for_review',
            description: 'Task submitted for review',
            userId: $this->employee1->id,
            taskId: $this->task->id,
        );

        Sanctum::actingAs($this->teamLead);

        $res = $this->getJson("/api/v1/tasks/{$this->task->id}/activities?action=task_submitted_for_review");
        $res->assertStatus(200);
        $res->assertJsonPath('meta.total', 1);
        $res->assertJsonPath('data.0.action', 'task_submitted_for_review');
    }
}
