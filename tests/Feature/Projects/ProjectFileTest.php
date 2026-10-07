<?php

namespace Tests\Feature\Projects;

use App\Models\Company;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectFile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectFileTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $department;
    private User $teamLead;
    private User $member;
    private User $nonMember;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Capeonn Systems', 'code' => 'SYS']);
        $this->department = Department::create(['company_id' => $this->company->id, 'name' => 'Design', 'code' => 'DSN']);

        $empRole = Role::where('slug', Role::EMPLOYEE)->firstOrFail();
        $tlRole = Role::where('slug', Role::TEAM_LEAD)->firstOrFail();

        $this->teamLead = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $tlRole->id,
            'name' => 'Lead Leadson',
        ]);

        $this->member = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
            'name' => 'Member Memberson',
        ]);

        $this->nonMember = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
            'name' => 'Outsider Out',
        ]);

        $this->project = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'name' => 'Portal UI',
            'code' => 'SYS-UI',
            'status' => 'active',
            'priority' => 'medium',
            'start_date' => now(),
            'deadline' => now()->addWeeks(2),
            'team_lead_id' => $this->teamLead->id,
            'created_by_id' => $this->teamLead->id,
        ]);

        $this->project->members()->attach($this->member->id, ['project_role' => 'contributor', 'assigned_at' => now()]);
    }

    public function test_project_member_can_upload_file_and_logs_activity(): void
    {
        Sanctum::actingAs($this->member);

        $file = UploadedFile::fake()->create('wireframes.pdf', 500, 'application/pdf');

        $response = $this->postJson("/api/v1/projects/{$this->project->id}/files", [
            'file' => $file,
            'category' => 'design',
            'description' => 'Initial mobile screen wireframes',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.file_name', 'wireframes.pdf')
            ->assertJsonPath('data.category', 'design')
            ->assertJsonPath('data.description', 'Initial mobile screen wireframes');

        $this->assertDatabaseHas('project_files', [
            'project_id' => $this->project->id,
            'file_name' => 'wireframes.pdf',
            'category' => 'design',
        ]);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'action' => 'file_uploaded',
            'user_id' => $this->member->id,
        ]);
    }

    public function test_non_member_cannot_view_or_upload_files(): void
    {
        Sanctum::actingAs($this->nonMember);

        $getRes = $this->getJson("/api/v1/projects/{$this->project->id}/files");
        $getRes->assertStatus(403);

        $file = UploadedFile::fake()->create('hacked.txt', 10, 'text/plain');
        $postRes = $this->postJson("/api/v1/projects/{$this->project->id}/files", [
            'file' => $file,
        ]);
        $postRes->assertStatus(403);
    }

    public function test_cannot_upload_file_when_project_is_closed(): void
    {
        $this->project->update(['status' => 'completed']);

        Sanctum::actingAs($this->member);

        $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');
        $response = $this->postJson("/api/v1/projects/{$this->project->id}/files", [
            'file' => $file,
        ]);

        $response->assertStatus(403);
    }

    public function test_uploader_or_lead_can_delete_file(): void
    {
        Sanctum::actingAs($this->member);

        $file = UploadedFile::fake()->create('specs.docx', 200, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $uploadRes = $this->postJson("/api/v1/projects/{$this->project->id}/files", [
            'file' => $file,
        ]);
        $fileId = $uploadRes->json('data.id');

        // Uploader can delete
        $delRes = $this->deleteJson("/api/v1/projects/{$this->project->id}/files/{$fileId}");
        $delRes->assertStatus(200);

        $this->assertSoftDeleted('project_files', ['id' => $fileId]);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $this->project->id,
            'action' => 'file_deleted',
        ]);
    }
}
