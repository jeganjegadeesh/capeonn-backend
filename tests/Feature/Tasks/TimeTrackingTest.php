<?php

namespace Tests\Feature\Tasks;

use App\Models\Company;
use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TimeTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $department;
    private User $manager;
    private User $teamLead;
    private User $employee;
    private Project $project;
    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Capeonn Technologies', 'code' => 'CAP']);
        $this->department = Department::create(['company_id' => $this->company->id, 'name' => 'Engineering', 'code' => 'ENG']);

        $mgrRole = Role::where('slug', Role::MANAGER)->firstOrFail();
        $tlRole = Role::where('slug', Role::TEAM_LEAD)->firstOrFail();
        $empRole = Role::where('slug', Role::EMPLOYEE)->firstOrFail();

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

        $this->employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
            'reports_to_id' => $this->teamLead->id,
        ]);

        $this->project = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'manager_id' => $this->manager->id,
            'team_lead_id' => $this->teamLead->id,
            'created_by_id' => $this->manager->id,
            'name' => 'Cloud Migration',
            'code' => 'CLM-001',
            'status' => Project::STATUS_ACTIVE,
            'priority' => Project::PRIORITY_HIGH,
        ]);

        $this->project->members()->attach($this->employee->id, [
            'project_role' => 'Developer',
        ]);

        $this->task = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'created_by_id' => $this->teamLead->id,
            'assigned_to_id' => $this->employee->id,
            'title' => 'Build S3 upload adapter',
            'status' => Task::STATUS_ASSIGNED,
            'estimated_hours' => 5.0,
        ]);
    }

    public function test_user_can_start_timer_and_task_auto_transitions_to_in_progress(): void
    {
        Sanctum::actingAs($this->employee);

        $res = $this->postJson("/api/v1/tasks/{$this->task->id}/timer/start", [
            'description' => 'Working on S3 client configuration',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.task_id', $this->task->id)
            ->assertJsonPath('data.is_running', true)
            ->assertJsonPath('data.is_manual', false);

        $this->assertEquals(Task::STATUS_IN_PROGRESS, $this->task->fresh()->status);
        $this->assertNotNull($this->task->fresh()->started_at);

        $this->assertDatabaseHas('time_entries', [
            'task_id' => $this->task->id,
            'user_id' => $this->employee->id,
            'ended_at' => null,
        ]);
    }

    public function test_starting_new_timer_automatically_stops_prior_running_timer(): void
    {
        Sanctum::actingAs($this->employee);

        $task2 = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'created_by_id' => $this->teamLead->id,
            'assigned_to_id' => $this->employee->id,
            'title' => 'Configure IAM Policies',
            'status' => Task::STATUS_ASSIGNED,
        ]);

        // Start timer on task 1
        $this->postJson("/api/v1/tasks/{$this->task->id}/timer/start")->assertStatus(201);
        $entry1 = TimeEntry::where('task_id', $this->task->id)->running()->first();
        $this->assertNotNull($entry1);

        // Advance time a little
        Carbon::setTestNow(now()->addMinutes(30));

        // Start timer on task 2
        $this->postJson("/api/v1/tasks/{$task2->id}/timer/start")->assertStatus(201);

        // Entry 1 should now be stopped
        $this->assertNotNull($entry1->fresh()->ended_at);
        $this->assertGreaterThan(0, $entry1->fresh()->duration_seconds);

        // Entry 2 should be running
        $entry2 = TimeEntry::where('task_id', $task2->id)->running()->first();
        $this->assertNotNull($entry2);

        Carbon::setTestNow();
    }

    public function test_stop_timer_calculates_duration_and_updates_task_actual_hours(): void
    {
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/v1/tasks/{$this->task->id}/timer/start")->assertStatus(201);

        // Simulate 2 hours elapsed
        Carbon::setTestNow(now()->addHours(2));

        $res = $this->postJson("/api/v1/tasks/{$this->task->id}/timer/stop", [
            'description' => 'Completed adapter interface implementation',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.is_running', false);

        $this->assertEquals(2.0, (float) $this->task->fresh()->actual_hours);

        Carbon::setTestNow();
    }

    public function test_manual_time_entry_logging(): void
    {
        Sanctum::actingAs($this->employee);

        $start = now()->subHours(3)->toIso8601String();
        $end = now()->subHours(1)->toIso8601String();

        $res = $this->postJson("/api/v1/tasks/{$this->task->id}/time-entries", [
            'started_at' => $start,
            'ended_at' => $end,
            'description' => 'Offline schema design and code review',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.is_manual', true)
            ->assertJsonPath('data.duration_hours', 2);

        $this->assertEquals(2.0, (float) $this->task->fresh()->actual_hours);
    }

    public function test_active_timer_endpoint_returns_running_timer(): void
    {
        Sanctum::actingAs($this->employee);

        // Initially null
        $resNone = $this->getJson('/api/v1/time-entries/active');
        $resNone->assertStatus(200)->assertJsonPath('data', null);

        // Start timer
        $this->postJson("/api/v1/tasks/{$this->task->id}/timer/start")->assertStatus(201);

        // Active returned
        $resActive = $this->getJson('/api/v1/time-entries/active');
        $resActive->assertStatus(200)
            ->assertJsonPath('data.task_id', $this->task->id)
            ->assertJsonPath('data.is_running', true);
    }

    public function test_time_tracking_fails_if_project_does_not_accept_work(): void
    {
        $this->project->update(['status' => Project::STATUS_ON_HOLD]);

        Sanctum::actingAs($this->employee);

        $res = $this->postJson("/api/v1/tasks/{$this->task->id}/timer/start");
        $res->assertStatus(422)
            ->assertJsonFragment(['message' => 'Project status [on_hold] does not accept time tracking.']);
    }

    public function test_task_time_variance_calculation(): void
    {
        // 1. Task estimated at 5.0h, actual 0h -> none (not completed yet)
        $this->assertEquals('none', $this->task->time_variance);

        // While task is in_progress, variance is none even if hours logged
        $this->task->update(['actual_hours' => 3.0]);
        $this->assertEquals('none', $this->task->time_variance);

        // When task is completed:
        $this->task->update(['status' => Task::STATUS_COMPLETED]);

        // 2. Log 3.0h (less than 90% of 5.0h = 4.5h) -> early
        $this->assertEquals('early', $this->task->time_variance);

        // 3. Log 4.8h (within +/- 10% tolerance: 4.5h - 5.5h) -> on_time
        $this->task->update(['actual_hours' => 4.8]);
        $this->assertEquals('on_time', $this->task->time_variance);

        // 4. Log 5.0h -> on_time
        $this->task->update(['actual_hours' => 5.0]);
        $this->assertEquals('on_time', $this->task->time_variance);

        // 5. Log 7.5h (more than 110% of 5.0h = 5.5h) -> over_time
        $this->task->update(['actual_hours' => 7.5]);
        $this->assertEquals('over_time', $this->task->time_variance);
    }
}
