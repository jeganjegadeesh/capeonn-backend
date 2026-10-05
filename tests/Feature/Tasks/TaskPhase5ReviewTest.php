<?php

namespace Tests\Feature\Tasks;

use App\Models\Company;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Role;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskPhase5ReviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $department;
    private User $admin;
    private User $manager;
    private User $teamLead;
    private User $employee1;
    private User $employee2;
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

        $this->employee1 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
            'reports_to_id' => $this->teamLead->id,
        ]);

        $this->employee2 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
            'reports_to_id' => $this->teamLead->id,
        ]);

        $this->project = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'name' => 'Cloud Migration',
            'code' => 'MIG-01',
            'status' => Project::STATUS_ACTIVE,
            'manager_id' => $this->manager->id,
            'team_lead_id' => $this->teamLead->id,
            'created_by_id' => $this->admin->id,
            'deadline' => Carbon::now()->addMonths(2)->toDateString(),
        ]);

        $this->project->members()->attach($this->employee1->id, ['project_role' => 'Developer']);
        $this->project->members()->attach($this->employee2->id, ['project_role' => 'QA']);
    }

    /** 1. An assignee cannot move a task from review to completed, but a Team Lead can. */
    public function test_assignee_cannot_move_task_from_review_to_completed_but_team_lead_can(): void
    {
        $task = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'API Review Task',
            'status'         => Task::STATUS_REVIEW,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        // Assignee attempts to approve own work -> 403 Forbidden
        Sanctum::actingAs($this->employee1);
        $res = $this->postJson("/api/v1/tasks/{$task->id}/status", [
            'status' => Task::STATUS_COMPLETED,
        ]);
        $res->assertStatus(403)
            ->assertJsonFragment(['message' => 'Assignees cannot approve their own work.']);

        // Team Lead approves the task -> 200 OK
        Sanctum::actingAs($this->teamLead);
        $res = $this->postJson("/api/v1/tasks/{$task->id}/status", [
            'status' => Task::STATUS_COMPLETED,
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.status', Task::STATUS_COMPLETED);

        $this->assertEquals(Task::STATUS_COMPLETED, $task->fresh()->status);
    }

    /** 2. A subtask created through the subtask endpoint has the right parent_task_id. */
    public function test_subtask_created_through_subtask_endpoint_has_right_parent_task_id(): void
    {
        $parent = Task::create([
            'company_id'    => $this->company->id,
            'project_id'    => $this->project->id,
            'title'         => 'Parent Root Task',
            'status'        => Task::STATUS_ASSIGNED,
            'created_by_id' => $this->teamLead->id,
        ]);

        Sanctum::actingAs($this->teamLead);
        $res = $this->postJson("/api/v1/tasks/{$parent->id}/subtasks", [
            'title'       => 'Child Subtask 1',
            'description' => 'Implementation piece',
            'priority'    => Task::PRIORITY_HIGH,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.parent_task_id', $parent->id);

        $subtaskId = $res->json('data.id');
        $subtask = Task::find($subtaskId);
        $this->assertNotNull($subtask);
        $this->assertEquals($parent->id, $subtask->parent_task_id);

        // Nested subtasks beyond one level are rejected
        $nestedRes = $this->postJson("/api/v1/tasks/{$subtask->id}/subtasks", [
            'title' => 'Nested Child Level 2',
        ]);
        $nestedRes->assertStatus(422)
            ->assertJsonValidationErrors(['parent_task_id']);
    }

    /** 3. Auto-stop of a long-running timer. */
    public function test_auto_stop_of_a_long_running_timer(): void
    {
        $task = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Forgotten Timer Task',
            'status'         => Task::STATUS_IN_PROGRESS,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        $startedAt = Carbon::now()->subHours(14);
        $entry = TimeEntry::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'task_id'    => $task->id,
            'user_id'    => $this->employee1->id,
            'started_at' => $startedAt,
            'is_manual'  => false,
        ]);

        $this->assertTrue($entry->is_running);

        // Run auto-stop command with 12 hour threshold
        $this->artisan('capeonn:auto-stop-timers --max-hours=12')
            ->assertExitCode(0);

        $entry->refresh();
        $this->assertFalse($entry->is_running);
        $this->assertTrue($entry->is_auto_stopped);
        $this->assertNotNull($entry->ended_at);
        $this->assertEquals(12.0, $entry->duration_hours);
        $this->assertEquals(12.0, (float) $task->fresh()->actual_hours);
    }

    /** 4. Two simultaneous timer starts leave exactly one running timer. */
    public function test_two_simultaneous_timer_starts_leave_exactly_one_running_timer(): void
    {
        $task1 = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Task Alpha',
            'status'         => Task::STATUS_ASSIGNED,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        $task2 = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Task Beta',
            'status'         => Task::STATUS_ASSIGNED,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        Sanctum::actingAs($this->employee1);

        // Start timer on task 1
        $res1 = $this->postJson("/api/v1/tasks/{$task1->id}/timer/start");
        $res1->assertStatus(201);

        // Immediately start timer on task 2
        $res2 = $this->postJson("/api/v1/tasks/{$task2->id}/timer/start");
        $res2->assertStatus(201);

        // Verify only 1 timer is currently running for this user
        $runningCount = TimeEntry::where('user_id', $this->employee1->id)->running()->count();
        $this->assertEquals(1, $runningCount);

        // And it is running on task 2
        $active = TimeEntry::where('user_id', $this->employee1->id)->running()->first();
        $this->assertEquals($task2->id, $active->task_id);
    }

    /** 5. Cannot delete a task that has time entries. */
    public function test_cannot_delete_a_task_that_has_time_entries(): void
    {
        $task = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Task With Logged Hours',
            'status'         => Task::STATUS_IN_PROGRESS,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        TimeEntry::create([
            'company_id'       => $this->company->id,
            'project_id'       => $this->project->id,
            'task_id'          => $task->id,
            'user_id'          => $this->employee1->id,
            'started_at'       => Carbon::now()->subHours(2),
            'ended_at'         => Carbon::now()->subHours(1),
            'duration_seconds' => 3600,
            'is_manual'        => true,
        ]);

        Sanctum::actingAs($this->teamLead);
        $res = $this->deleteJson("/api/v1/tasks/{$task->id}");
        $res->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot delete a task that has logged time entries. Archive or cancel the task instead.']);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'deleted_at' => null]);
    }

    /** 6. Cannot delete time entries on a completed task or in a closed project. */
    public function test_cannot_delete_time_entries_on_a_completed_task_or_in_a_closed_project(): void
    {
        $task = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Finished Task',
            'status'         => Task::STATUS_COMPLETED,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        $entry = TimeEntry::create([
            'company_id'       => $this->company->id,
            'project_id'       => $this->project->id,
            'task_id'          => $task->id,
            'user_id'          => $this->employee1->id,
            'started_at'       => Carbon::now()->subHours(3),
            'ended_at'         => Carbon::now()->subHours(1),
            'duration_seconds' => 7200,
            'is_manual'        => true,
        ]);

        // Regular employee cannot delete time entry on completed task
        Sanctum::actingAs($this->employee1);
        $res = $this->deleteJson("/api/v1/time-entries/{$entry->id}");
        $res->assertStatus(403);

        // Team Lead must provide a reason
        Sanctum::actingAs($this->teamLead);
        $resNoReason = $this->deleteJson("/api/v1/time-entries/{$entry->id}");
        $resNoReason->assertStatus(422)
            ->assertJsonFragment(['message' => 'A reason is required to delete a time entry on a completed task.']);

        // Team Lead with reason succeeds
        $resWithReason = $this->deleteJson("/api/v1/time-entries/{$entry->id}", [
            'reason' => 'Logged wrong hours by mistake',
        ]);
        $resWithReason->assertStatus(200);

        // Project closed: no deletions allowed
        $this->project->update(['status' => Project::STATUS_COMPLETED]);
        $entry2 = TimeEntry::create([
            'company_id'       => $this->company->id,
            'project_id'       => $this->project->id,
            'task_id'          => $task->id,
            'user_id'          => $this->employee1->id,
            'started_at'       => Carbon::now()->subHours(2),
            'ended_at'         => Carbon::now()->subHours(1),
            'duration_seconds' => 3600,
            'is_manual'        => true,
        ]);

        Sanctum::actingAs($this->teamLead);
        $closedRes = $this->deleteJson("/api/v1/time-entries/{$entry2->id}", ['reason' => 'Fix']);
        $closedRes->assertStatus(422)
            ->assertJsonFragment(['message' => 'Project status [completed] does not accept time entry deletion.']);
    }

    /** 7. Timer rejected on tasks in review or completed. */
    public function test_timer_rejected_on_tasks_in_review_or_completed(): void
    {
        $reviewTask = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'In Review Task',
            'status'         => Task::STATUS_REVIEW,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        $completedTask = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Already Done Task',
            'status'         => Task::STATUS_COMPLETED,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        Sanctum::actingAs($this->employee1);

        $res1 = $this->postJson("/api/v1/tasks/{$reviewTask->id}/timer/start");
        $res1->assertStatus(403);

        $res2 = $this->postJson("/api/v1/tasks/{$completedTask->id}/timer/start");
        $res2->assertStatus(403);
    }

    /** 8. No false due-date entry in the log when the date is unchanged. */
    public function test_no_false_due_date_entry_in_the_log_when_the_date_is_unchanged(): void
    {
        $task = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Audit Date Task',
            'status'         => Task::STATUS_ASSIGNED,
            'due_date'       => '2026-10-15',
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        Sanctum::actingAs($this->teamLead);

        // Update title and description, keep same due_date string
        $res = $this->putJson("/api/v1/tasks/{$task->id}", [
            'title'       => 'Audit Date Task (Updated)',
            'description' => 'Updated details',
            'due_date'    => '2026-10-15',
        ]);
        $res->assertStatus(200);

        // Check project activity logs: there should be NO activity where field == 'due_date'
        $dueDateLogs = ProjectActivity::where('project_id', $this->project->id)
            ->where('task_id', $task->id)
            ->where('field', 'due_date')
            ->count();

        $this->assertEquals(0, $dueDateLogs);
    }

    /** 9. Variance tolerance (early, on_time, over_time). */
    public function test_variance_tolerance(): void
    {
        $task = Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->project->id,
            'title'           => 'Variance Test Task',
            'status'          => Task::STATUS_IN_PROGRESS,
            'estimated_hours' => 10.0,
            'actual_hours'    => 5.0,
            'created_by_id'   => $this->teamLead->id,
        ]);

        // Unfinished task always has variance 'none'
        $this->assertEquals(Task::VARIANCE_NONE, $task->time_variance);

        // Mark as completed
        $task->status = Task::STATUS_COMPLETED;

        // 8.0h is 80% (less than 90%) -> early
        $task->actual_hours = 8.0;
        $this->assertEquals(Task::VARIANCE_EARLY, $task->time_variance);

        // 9.5h is 95% (within +/- 10% tolerance: 9.0h - 11.0h) -> on_time
        $task->actual_hours = 9.5;
        $this->assertEquals(Task::VARIANCE_ON_TIME, $task->time_variance);

        // 10.5h is 105% (within +/- 10%) -> on_time
        $task->actual_hours = 10.5;
        $this->assertEquals(Task::VARIANCE_ON_TIME, $task->time_variance);

        // 12.0h is 120% (> 110%) -> over_time
        $task->actual_hours = 12.0;
        $this->assertEquals(Task::VARIANCE_OVER_TIME, $task->time_variance);
    }

    /** 10. An employee cannot see other people’s time entries. */
    public function test_employee_cannot_see_other_peoples_time_entries(): void
    {
        $task = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Shared Work Task',
            'status'         => Task::STATUS_IN_PROGRESS,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        // Employee 1 entry
        TimeEntry::create([
            'company_id'       => $this->company->id,
            'project_id'       => $this->project->id,
            'task_id'          => $task->id,
            'user_id'          => $this->employee1->id,
            'started_at'       => Carbon::now()->subHours(4),
            'ended_at'         => Carbon::now()->subHours(2),
            'duration_seconds' => 7200,
            'is_manual'        => true,
        ]);

        // Employee 2 entry
        TimeEntry::create([
            'company_id'       => $this->company->id,
            'project_id'       => $this->project->id,
            'task_id'          => $task->id,
            'user_id'          => $this->employee2->id,
            'started_at'       => Carbon::now()->subHours(2),
            'ended_at'         => Carbon::now()->subHours(1),
            'duration_seconds' => 3600,
            'is_manual'        => true,
        ]);

        // Employee 2 views task: should see ONLY their own time entry (1 entry)
        Sanctum::actingAs($this->employee2);
        $res = $this->getJson("/api/v1/tasks/{$task->id}");
        $res->assertStatus(200);
        $entries = $res->json('data.time_entries');
        $this->assertCount(1, $entries);
        $this->assertEquals($this->employee2->id, $entries[0]['user_id']);

        // Team Lead views task: sees both time entries
        Sanctum::actingAs($this->teamLead);
        $tlRes = $this->getJson("/api/v1/tasks/{$task->id}");
        $tlRes->assertStatus(200);
        $this->assertCount(2, $tlRes->json('data.time_entries'));
    }

    /** 11. A running timer stops on reassignment. */
    public function test_running_timer_stops_on_reassignment(): void
    {
        $task = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Reassigned Task',
            'status'         => Task::STATUS_IN_PROGRESS,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        $timer = TimeEntry::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'task_id'    => $task->id,
            'user_id'    => $this->employee1->id,
            'started_at' => Carbon::now()->subHours(1),
            'is_manual'  => false,
        ]);

        $this->assertTrue($timer->is_running);

        // Team Lead reassigns task to Employee 2
        Sanctum::actingAs($this->teamLead);
        $res = $this->postJson("/api/v1/tasks/{$task->id}/assign", [
            'assigned_to_id' => $this->employee2->id,
            'reason'         => 'Workload balancing',
        ]);
        $res->assertStatus(200);

        // Old assignee's timer must be stopped
        $timer->refresh();
        $this->assertFalse($timer->is_running);
        $this->assertNotNull($timer->ended_at);

        // Activity log contains timer_stopped
        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'task_id'    => $task->id,
            'action'     => 'timer_stopped',
        ]);
    }

    /** 12. Project metrics count root tasks only. */
    public function test_project_metrics_count_root_tasks_only(): void
    {
        // Root task 1: completed
        Task::create([
            'company_id'    => $this->company->id,
            'project_id'    => $this->project->id,
            'title'         => 'Root Task 1',
            'status'        => Task::STATUS_COMPLETED,
            'created_by_id' => $this->teamLead->id,
        ]);

        // Root task 2: in_progress with subtasks
        $root2 = Task::create([
            'company_id'    => $this->company->id,
            'project_id'    => $this->project->id,
            'title'         => 'Root Task 2',
            'status'        => Task::STATUS_IN_PROGRESS,
            'created_by_id' => $this->teamLead->id,
        ]);

        // Subtask 1 under root 2: completed
        Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'parent_task_id' => $root2->id,
            'title'          => 'Subtask 1',
            'status'         => Task::STATUS_COMPLETED,
            'created_by_id'  => $this->teamLead->id,
        ]);

        // Subtask 2 under root 2: completed
        Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'parent_task_id' => $root2->id,
            'title'          => 'Subtask 2',
            'status'         => Task::STATUS_COMPLETED,
            'created_by_id'  => $this->teamLead->id,
        ]);

        // Subtask 3 under root 2: in_progress
        Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'parent_task_id' => $root2->id,
            'title'          => 'Subtask 3',
            'status'         => Task::STATUS_IN_PROGRESS,
            'created_by_id'  => $this->teamLead->id,
        ]);

        $metrics = $this->project->fresh()->task_metrics;

        // Total root tasks: 2 (not 5)
        $this->assertEquals(2, $metrics['total_tasks']);

        // Completed root tasks: 1 (not 3)
        $this->assertEquals(1, $metrics['completed_tasks']);

        // Progress percentage: 50% (1/2, not 3/5 = 60%)
        $this->assertEquals(50, $metrics['progress_percentage']);
    }

    /** 13. Pause and resume running timer. */
    public function test_pause_and_resume_timer(): void
    {
        $task = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Pause Resume Task',
            'status'         => Task::STATUS_ASSIGNED,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        Sanctum::actingAs($this->employee1);

        // Start timer
        $this->postJson("/api/v1/tasks/{$task->id}/timer/start")->assertStatus(201);

        // Pause timer
        $pauseRes = $this->postJson("/api/v1/tasks/{$task->id}/timer/pause");
        $pauseRes->assertStatus(200)
            ->assertJsonPath('data.is_paused', true);

        // Resume timer
        $resumeRes = $this->postJson("/api/v1/tasks/{$task->id}/timer/resume");
        $resumeRes->assertStatus(200)
            ->assertJsonPath('data.is_paused', false);
    }

    /** 14. My work today summary. */
    public function test_my_work_today_summary(): void
    {
        $today = Carbon::today()->toDateString();

        Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Due Today Task',
            'status'         => Task::STATUS_ASSIGNED,
            'due_date'       => $today,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Overdue Task',
            'status'         => Task::STATUS_IN_PROGRESS,
            'due_date'       => Carbon::today()->subDays(2)->toDateString(),
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        Sanctum::actingAs($this->employee1);
        $res = $this->getJson('/api/v1/tasks/my-work-today');
        $res->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'due_today_tasks',
                    'overdue_tasks',
                    'in_progress_tasks',
                    'hours_logged_today',
                    'active_timer',
                ],
            ]);

        $this->assertCount(1, $res->json('data.due_today_tasks'));
        $this->assertCount(1, $res->json('data.overdue_tasks'));
    }

    /** 15. Timesheet and team timesheet endpoints. */
    public function test_timesheet_and_team_timesheet(): void
    {
        $task = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'title'          => 'Timesheet Task',
            'status'         => Task::STATUS_IN_PROGRESS,
            'assigned_to_id' => $this->employee1->id,
            'created_by_id'  => $this->teamLead->id,
        ]);

        TimeEntry::create([
            'company_id'       => $this->company->id,
            'project_id'       => $this->project->id,
            'task_id'          => $task->id,
            'user_id'          => $this->employee1->id,
            'started_at'       => Carbon::today()->setHour(9),
            'ended_at'         => Carbon::today()->setHour(12),
            'duration_seconds' => 10800, // 3 hours
            'is_manual'        => true,
        ]);

        // User timesheet
        Sanctum::actingAs($this->employee1);
        $res = $this->getJson('/api/v1/timesheet');
        $res->assertStatus(200);
        $this->assertEquals(3.0, (float) $res->json('data.total_hours'));

        // Team timesheet (Team Lead)
        Sanctum::actingAs($this->teamLead);
        $teamRes = $this->getJson('/api/v1/timesheet/team');
        $teamRes->assertStatus(200);
        $this->assertEquals(3.0, (float) $teamRes->json('data.total_hours'));
    }
}
