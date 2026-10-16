<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaunchSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_seeds_only_essential_launch_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        // 1. Roles & Permissions exist
        $this->assertDatabaseHas('roles', ['slug' => Role::SUPER_ADMIN]);
        $this->assertDatabaseHas('roles', ['slug' => 'admin']);
        $this->assertDatabaseHas('roles', ['slug' => Role::HR]);
        $this->assertDatabaseHas('roles', ['slug' => Role::MANAGER]);
        $this->assertDatabaseHas('roles', ['slug' => Role::TEAM_LEAD]);
        $this->assertDatabaseHas('roles', ['slug' => Role::EMPLOYEE]);

        // 2. Company & exactly 1 Super Admin exist
        $company = Company::first();
        $this->assertNotNull($company);
        $this->assertEquals(1, User::count());
        $admin = User::first();
        $this->assertTrue($admin->isSuperAdmin() || $admin->isAdmin());

        // 3. Essential Leave Types exist
        $this->assertDatabaseHas('leave_types', ['code' => 'CL', 'company_id' => $company->id]);
        $this->assertDatabaseHas('leave_types', ['code' => 'SL', 'company_id' => $company->id]);
        $this->assertDatabaseHas('leave_types', ['code' => 'PL', 'company_id' => $company->id]);
        $this->assertDatabaseHas('leave_types', ['code' => 'LOP', 'company_id' => $company->id]);

        // 4. Primary Office Location exists
        $this->assertDatabaseHas('office_locations', ['company_id' => $company->id, 'is_active' => true]);

        // 5. Official Holidays exist
        $this->assertGreaterThan(0, Holiday::where('company_id', $company->id)->count());

        // 6. Zero demo data: NO demo projects, NO demo tasks, NO demo chat/attendance
        $this->assertEquals(0, Project::count());
        $this->assertEquals(0, Task::count());
        $this->assertEquals(0, \App\Models\Attendance::count());
        $this->assertEquals(0, \App\Models\ChatMessage::count());
        $this->assertEquals(0, \App\Models\Conversation::count());

        // 7. Test Idempotency (running again causes no duplicates or errors)
        $this->seed(DatabaseSeeder::class);
        $this->assertEquals(1, User::count());
        $this->assertEquals(4, LeaveType::count());
        $this->assertEquals(1, OfficeLocation::count());
    }
}
