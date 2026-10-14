<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskReview;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsPhase9Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $deptA;
    private Department $deptB;
    private User $superAdmin;
    private User $managerA;
    private User $teamLeadA;
    private User $employeeA;
    private User $employeeB;
    private Project $projectA;
    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Capeonn Ltd', 'code' => 'CPN', 'is_active' => true]);

        $this->deptA = Department::create([
            'company_id' => $this->company->id,
            'name'       => 'Engineering',
            'code'       => 'ENG',
        ]);

        $this->deptB = Department::create([
            'company_id' => $this->company->id,
            'name'       => 'Design',
            'code'       => 'DSG',
        ]);

        $roleSuperAdmin = Role::where('slug', Role::SUPER_ADMIN)->first();
        $roleManager = Role::where('slug', 'manager')->first();
        $roleLead = Role::where('slug', 'team_lead')->first();
        $roleEmployee = Role::where('slug', 'employee')->first();

        $this->superAdmin = User::create([
            'company_id' => $this->company->id,
            'role_id'    => $roleSuperAdmin->id,
            'name'       => 'Super Admin',
            'email'      => 'admin@capeonn.test',
            'password'   => bcrypt('Secret123!'),
            'is_active'  => true,
        ]);

        $this->managerA = User::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->deptA->id,
            'role_id'       => $roleManager->id,
            'name'          => 'Alice Manager',
            'email'         => 'alice@capeonn.test',
            'password'      => bcrypt('Secret123!'),
            'is_active'     => true,
        ]);

        $this->teamLeadA = User::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->deptA->id,
            'role_id'       => $roleLead->id,
            'reports_to_id' => $this->managerA->id,
            'name'          => 'Bob TeamLead',
            'email'         => 'bob@capeonn.test',
            'password'      => bcrypt('Secret123!'),
            'is_active'     => true,
        ]);

        $this->employeeA = User::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->deptA->id,
            'role_id'       => $roleEmployee->id,
            'reports_to_id' => $this->teamLeadA->id,
            'name'          => 'Charlie Developer',
            'email'         => 'charlie@capeonn.test',
            'employee_code' => 'CPN-001',
            'password'      => bcrypt('Secret123!'),
            'is_active'     => true,
        ]);

        $this->employeeB = User::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->deptB->id,
            'role_id'       => $roleEmployee->id,
            'name'          => 'Diana Designer',
            'email'         => 'diana@capeonn.test',
            'employee_code' => 'CPN-002',
            'password'      => bcrypt('Secret123!'),
            'is_active'     => true,
        ]);

        // Projects
        $this->projectA = Project::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->deptA->id,
            'manager_id'    => $this->managerA->id,
            'team_lead_id'  => $this->teamLeadA->id,
            'created_by_id' => $this->managerA->id,
            'name'          => 'Project Alpha',
            'code'          => 'PRJ-A',
            'status'        => Project::STATUS_ACTIVE,
            'priority'      => Project::PRIORITY_HIGH,
            'deadline'      => Carbon::today()->addDays(5)->toDateString(),
        ]);
        $this->projectA->members()->attach($this->employeeA->id, ['project_role' => 'Developer', 'assigned_by_id' => $this->teamLeadA->id]);

        $this->projectB = Project::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->deptB->id,
            'created_by_id' => $this->superAdmin->id,
            'name'          => 'Project Beta',
            'code'          => 'PRJ-B',
            'status'        => Project::STATUS_ACTIVE,
            'priority'      => Project::PRIORITY_MEDIUM,
            'deadline'      => Carbon::today()->subDays(3)->toDateString(),
        ]);
        $this->projectB->members()->attach($this->employeeB->id, ['project_role' => 'Designer', 'assigned_by_id' => $this->superAdmin->id]);
    }

    public function test_employee_is_forbidden_from_viewing_reports(): void
    {
        Sanctum::actingAs($this->employeeA);

        $response = $this->getJson('/api/v1/reports/dashboard');
        $response->assertStatus(403);

        $response = $this->getJson('/api/v1/reports/workload');
        $response->assertStatus(403);
    }

    public function test_super_admin_can_view_dashboard_summary(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Seed some tasks
        Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->projectA->id,
            'title'           => 'Task 1 in Dept A',
            'status'          => Task::STATUS_IN_PROGRESS,
            'priority'        => Task::PRIORITY_HIGH,
            'assigned_to_id'  => $this->employeeA->id,
            'due_date'        => Carbon::today()->addDays(2),
            'estimated_hours' => 10,
            'actual_hours'    => 4,
        ]);

        Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->projectB->id,
            'title'           => 'Task 2 in Dept B',
            'status'          => Task::STATUS_COMPLETED,
            'priority'        => Task::PRIORITY_MEDIUM,
            'assigned_to_id'  => $this->employeeB->id,
            'completed_at'    => Carbon::now(),
            'due_date'        => Carbon::today()->addDays(1),
            'estimated_hours' => 8,
            'actual_hours'    => 8,
        ]);

        $response = $this->getJson('/api/v1/reports/dashboard');
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'projects' => ['total', 'active', 'planned', 'on_hold', 'completed', 'overdue', 'due_soon'],
                    'tasks'    => ['total', 'backlog', 'assigned', 'in_progress', 'review', 'changes_required', 'completed', 'overdue', 'completion_rate', 'on_time_rate'],
                    'hours'    => ['estimated_hours', 'actual_hours', 'tracked_hours'],
                    'workload_summary' => ['total_members', 'overloaded', 'near_capacity', 'normal', 'underutilized'],
                    'attention' => ['urgent_overdue_tasks', 'due_soon_projects', 'stuck_reviews'],
                ],
            ]);

        // Super Admin sees projects from both departments (2 total)
        $this->assertEquals(2, $response->json('data.projects.total'));
        $this->assertEquals(2, $response->json('data.tasks.total'));
        $this->assertEquals(50.0, $response->json('data.tasks.completion_rate'));
    }

    public function test_manager_dashboard_is_scoped_to_department(): void
    {
        Sanctum::actingAs($this->managerA);

        Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->projectA->id,
            'title'           => 'Task Dept A',
            'status'          => Task::STATUS_IN_PROGRESS,
            'assigned_to_id'  => $this->employeeA->id,
            'estimated_hours' => 5,
        ]);

        Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->projectB->id,
            'title'           => 'Task Dept B',
            'status'          => Task::STATUS_IN_PROGRESS,
            'assigned_to_id'  => $this->employeeB->id,
            'estimated_hours' => 15,
        ]);

        $response = $this->getJson('/api/v1/reports/dashboard');
        $response->assertOk();

        // Manager A should only see Project Alpha (1 project) and its tasks (1 task)
        $this->assertEquals(1, $response->json('data.projects.total'));
        $this->assertEquals(1, $response->json('data.tasks.total'));
    }

    public function test_employee_workload_report_calculates_capacity(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Assign Charlie 6 active tasks to make him overloaded
        for ($i = 1; $i <= 6; $i++) {
            Task::create([
                'company_id'      => $this->company->id,
                'project_id'      => $this->projectA->id,
                'title'           => "Active Task {$i}",
                'status'          => Task::STATUS_IN_PROGRESS,
                'assigned_to_id'  => $this->employeeA->id,
                'estimated_hours' => 8,
            ]);
        }

        $response = $this->getJson('/api/v1/reports/workload');
        $response->assertOk()
            ->assertJsonPath('success', true);

        $items = collect($response->json('data.items'));
        $charlie = $items->firstWhere('user_id', $this->employeeA->id);

        $this->assertNotNull($charlie);
        $this->assertEquals(6, $charlie['active_tasks_count']);
        $this->assertEquals('overloaded', $charlie['workload_status']);
        $this->assertEquals(48.0, $charlie['backlog_hours']);
    }

    public function test_task_completion_report_returns_velocity_and_pass_rate(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $task = Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->projectA->id,
            'title'           => 'Reviewed Task',
            'status'          => Task::STATUS_COMPLETED,
            'assigned_to_id'  => $this->employeeA->id,
            'completed_at'    => Carbon::now(),
            'created_at'      => Carbon::now()->subHours(24),
        ]);

        // Review approved in round 1
        TaskReview::create([
            'company_id'      => $this->company->id,
            'task_id'         => $task->id,
            'round_number'    => 1,
            'submitted_by_id' => $this->employeeA->id,
            'submitted_at'    => Carbon::now()->subHours(2),
            'reviewer_id'     => $this->teamLeadA->id,
            'decided_at'      => Carbon::now()->subHour(),
            'outcome'         => 'approved',
        ]);

        $response = $this->getJson('/api/v1/reports/task-completion');
        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEquals(1, $response->json('data.summary.completed_tasks'));
        $this->assertEquals(100.0, $response->json('data.summary.first_time_pass_rate'));
        $this->assertNotEmpty($response->json('data.velocity_timeline'));
    }

    public function test_time_variance_report_categorizes_early_on_time_and_over_estimate(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Early task: estimated 10h, actual 5h (< 90%)
        Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->projectA->id,
            'title'           => 'Early Task',
            'status'          => Task::STATUS_COMPLETED,
            'assigned_to_id'  => $this->employeeA->id,
            'estimated_hours' => 10,
            'actual_hours'    => 5,
        ]);

        // On time task: estimated 10h, actual 10h (within 90-110%)
        Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->projectA->id,
            'title'           => 'On Time Task',
            'status'          => Task::STATUS_COMPLETED,
            'assigned_to_id'  => $this->employeeA->id,
            'estimated_hours' => 10,
            'actual_hours'    => 10,
        ]);

        // Overtime task: estimated 10h, actual 15h (> 110%)
        Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->projectA->id,
            'title'           => 'Overtime Task',
            'status'          => Task::STATUS_COMPLETED,
            'assigned_to_id'  => $this->employeeA->id,
            'estimated_hours' => 10,
            'actual_hours'    => 15,
        ]);

        $response = $this->getJson('/api/v1/reports/time-variance');
        $response->assertOk();

        $this->assertEquals(1, $response->json('data.summary.early_count'));
        $this->assertEquals(1, $response->json('data.summary.on_time_count'));
        $this->assertEquals(1, $response->json('data.summary.over_time_count'));
        $this->assertEquals(30.0, $response->json('data.summary.total_estimated_hours'));
        $this->assertEquals(30.0, $response->json('data.summary.total_actual_hours'));
    }

    public function test_overdue_tasks_report_groups_by_aging_buckets(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Task overdue by 2 days (bucket 1-3 days)
        Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->projectA->id,
            'title'           => 'Recent Overdue',
            'status'          => Task::STATUS_IN_PROGRESS,
            'priority'        => Task::PRIORITY_URGENT,
            'assigned_to_id'  => $this->employeeA->id,
            'due_date'        => Carbon::today()->subDays(2),
        ]);

        // Task overdue by 16 days (bucket 15+ days)
        Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->projectA->id,
            'title'           => 'Ancient Overdue',
            'status'          => Task::STATUS_IN_PROGRESS,
            'priority'        => Task::PRIORITY_HIGH,
            'assigned_to_id'  => $this->employeeA->id,
            'due_date'        => Carbon::today()->subDays(16),
        ]);

        $response = $this->getJson('/api/v1/reports/overdue-tasks');
        $response->assertOk();

        $this->assertEquals(2, $response->json('data.summary.total_overdue'));
        $this->assertEquals(1, $response->json('data.summary.aging_buckets.1_to_3_days'));
        $this->assertEquals(1, $response->json('data.summary.aging_buckets.15_plus_days'));
        $this->assertEquals(1, $response->json('data.summary.priority_breakdown.urgent'));
        $this->assertEquals(1, $response->json('data.summary.priority_breakdown.high'));
    }

    public function test_project_progress_report_calculates_health(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Project A has deadline in 5 days -> due_soon
        // Project B has deadline 3 days ago -> overdue
        $response = $this->getJson('/api/v1/reports/project-progress');
        $response->assertOk();

        $items = collect($response->json('data.items'));
        $projA = $items->firstWhere('project_id', $this->projectA->id);
        $projB = $items->firstWhere('project_id', $this->projectB->id);

        $this->assertEquals('due_soon', $projA['health']);
        $this->assertEquals('overdue', $projB['health']);
    }

    public function test_team_performance_report_computes_metrics(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $task = Task::create([
            'company_id'      => $this->company->id,
            'project_id'      => $this->projectA->id,
            'title'           => 'Delivered Task',
            'status'          => Task::STATUS_COMPLETED,
            'assigned_to_id'  => $this->employeeA->id,
            'due_date'        => Carbon::today()->addDays(1),
            'completed_at'    => Carbon::now(),
            'created_at'      => Carbon::now()->subHours(10),
        ]);

        TimeEntry::create([
            'company_id'       => $this->company->id,
            'project_id'       => $this->projectA->id,
            'task_id'          => $task->id,
            'user_id'          => $this->employeeA->id,
            'started_at'       => Carbon::now()->subHours(2),
            'ended_at'         => Carbon::now(),
            'duration_seconds' => 7200,
        ]);

        $response = $this->getJson('/api/v1/reports/team-performance');
        $response->assertOk();

        $items = collect($response->json('data.items'));
        $charlie = $items->firstWhere('user_id', $this->employeeA->id);

        $this->assertEquals(1, $charlie['completed_tasks_count']);
        $this->assertEquals(1, $charlie['on_time_tasks_count']);
        $this->assertEquals(100.0, $charlie['on_time_rate_percentage']);
        $this->assertEquals(2.0, $charlie['total_logged_hours']);
    }

    public function test_reports_csv_export_streams_csv_content(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->get('/api/v1/reports/export?type=workload');
        $response->assertOk();
        $this->assertTrue(str_contains($response->headers->get('content-type'), 'text/csv'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('Employee Name', $content);
        $this->assertStringContainsString('Email', $content);
        $this->assertStringContainsString('Charlie Developer', $content);
    }
}
