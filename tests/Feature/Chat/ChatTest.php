<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $department;
    private User $user1;
    private User $user2;
    private User $user3;
    private User $otherCompanyUser;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Capeonn Inc', 'code' => 'CAP']);
        $otherCompany = Company::create(['name' => 'Other Corp', 'code' => 'OTH']);

        $this->department = Department::create(['company_id' => $this->company->id, 'name' => 'Engineering', 'code' => 'ENG']);

        $empRole = Role::where('slug', Role::EMPLOYEE)->firstOrFail();
        $tlRole = Role::where('slug', Role::TEAM_LEAD)->firstOrFail();

        $this->user1 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $tlRole->id,
            'name' => 'Alice Team Lead',
        ]);

        $this->user2 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
            'name' => 'Bob Engineer',
        ]);

        $this->user3 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
            'name' => 'Charlie Designer',
        ]);

        $this->otherCompanyUser = User::factory()->create([
            'company_id' => $otherCompany->id,
            'role_id' => $empRole->id,
            'name' => 'Eve Other Corp',
        ]);

        $this->project = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'name' => 'Mobile Redesign',
            'code' => 'CAP-MOB',
            'status' => 'active',
            'priority' => 'high',
            'start_date' => now(),
            'deadline' => now()->addMonth(),
            'team_lead_id' => $this->user1->id,
            'created_by_id' => $this->user1->id,
        ]);

        $this->project->members()->attach($this->user2->id, ['project_role' => 'developer', 'assigned_at' => now()]);
    }

    public function test_can_start_direct_conversation_with_colleague(): void
    {
        Sanctum::actingAs($this->user1);

        $response = $this->postJson('/api/v1/conversations/direct', [
            'user_id' => $this->user2->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'direct');

        $convId = $response->json('data.id');

        // Calling again should return the exact same conversation (idempotent)
        $secondResponse = $this->postJson('/api/v1/conversations/direct', [
            'user_id' => $this->user2->id,
        ]);

        $secondResponse->assertStatus(200)
            ->assertJsonPath('data.id', $convId);
    }

    public function test_cannot_start_direct_chat_with_self_or_other_company(): void
    {
        Sanctum::actingAs($this->user1);

        // Self chat fails
        $selfRes = $this->postJson('/api/v1/conversations/direct', [
            'user_id' => $this->user1->id,
        ]);
        $selfRes->assertStatus(422);

        // Other company employee fails
        $otherRes = $this->postJson('/api/v1/conversations/direct', [
            'user_id' => $this->otherCompanyUser->id,
        ]);
        $otherRes->assertStatus(404);
    }

    public function test_can_create_group_conversation(): void
    {
        Sanctum::actingAs($this->user1);

        $response = $this->postJson('/api/v1/conversations/group', [
            'title' => 'Mobile Dev Squad',
            'participant_ids' => [$this->user2->id, $this->user3->id],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Mobile Dev Squad')
            ->assertJsonPath('data.type', 'group');

        $this->assertDatabaseHas('conversations', [
            'title' => 'Mobile Dev Squad',
            'type' => 'group',
            'created_by_id' => $this->user1->id,
        ]);

        $this->assertEquals(3, ConversationParticipant::where('conversation_id', $response->json('data.id'))->count());
    }

    public function test_project_conversation_auto_creates_and_syncs_members(): void
    {
        Sanctum::actingAs($this->user2);

        $response = $this->getJson("/api/v1/conversations/project/{$this->project->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.type', 'project')
            ->assertJsonPath('data.project_id', $this->project->id);

        $convId = $response->json('data.id');

        // User 3 (not member of project) cannot access it
        Sanctum::actingAs($this->user3);
        $deniedRes = $this->getJson("/api/v1/conversations/{$convId}");
        $deniedRes->assertStatus(403);
    }

    public function test_can_send_and_paginate_messages(): void
    {
        Sanctum::actingAs($this->user1);

        $convRes = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->user2->id]);
        $convId = $convRes->json('data.id');

        // Send message
        $sendRes = $this->postJson("/api/v1/conversations/{$convId}/messages", [
            'message' => 'Hello Bob, please review the mockups!',
        ]);

        $sendRes->assertStatus(201)
            ->assertJsonPath('data.message', 'Hello Bob, please review the mockups!')
            ->assertJsonPath('data.user.name', 'Alice Team Lead');

        $messageId = $sendRes->json('data.id');

        // Send a reply
        Sanctum::actingAs($this->user2);
        $replyRes = $this->postJson("/api/v1/conversations/{$convId}/messages", [
            'message' => 'Sure Alice, looking at them now.',
            'reply_to_id' => $messageId,
        ]);

        $replyRes->assertStatus(201)
            ->assertJsonPath('data.reply_to_id', $messageId);

        // Fetch messages list
        $listRes = $this->getJson("/api/v1/conversations/{$convId}/messages");
        $listRes->assertStatus(200);
        $this->assertCount(2, $listRes->json('data'));
    }

    public function test_can_send_message_with_attachment_and_task_link(): void
    {
        Sanctum::actingAs($this->user1);

        $task = Task::create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'title' => 'Design System Specs',
            'status' => 'assigned',
            'priority' => 'high',
            'assigned_to_id' => $this->user2->id,
            'created_by_id' => $this->user1->id,
        ]);

        $convRes = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->user2->id]);
        $convId = $convRes->json('data.id');

        $sendRes = $this->postJson("/api/v1/conversations/{$convId}/messages", [
            'message' => 'Here are the design assets for the task',
            'task_id' => $task->id,
            'attachments' => [
                [
                    'file_path' => 'uploads/spec.pdf',
                    'file_name' => 'spec.pdf',
                    'file_size' => 102400,
                    'mime_type' => 'application/pdf',
                ],
            ],
        ]);

        $sendRes->assertStatus(201)
            ->assertJsonPath('data.task.title', 'Design System Specs')
            ->assertJsonCount(1, 'data.attachments');

        $this->assertDatabaseHas('chat_attachments', [
            'file_name' => 'spec.pdf',
        ]);
    }

    public function test_read_receipt_and_unread_summary(): void
    {
        Sanctum::actingAs($this->user1);
        $convRes = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->user2->id]);
        $convId = $convRes->json('data.id');

        // Alice sends 2 messages
        $this->postJson("/api/v1/conversations/{$convId}/messages", ['message' => 'Msg 1']);
        $msg2 = $this->postJson("/api/v1/conversations/{$convId}/messages", ['message' => 'Msg 2']);
        $lastMsgId = $msg2->json('data.id');

        // Bob checks unread summary
        Sanctum::actingAs($this->user2);
        $summary = $this->getJson('/api/v1/conversations/unread-summary');
        $summary->assertStatus(200)
            ->assertJsonPath('data.total_unread', 2);

        // Bob marks as read
        $readRes = $this->postJson("/api/v1/conversations/{$convId}/read", [
            'last_read_message_id' => $lastMsgId,
        ]);
        $readRes->assertStatus(200);

        // Now Bob has 0 unread
        $summaryAfter = $this->getJson('/api/v1/conversations/unread-summary');
        $summaryAfter->assertStatus(200)
            ->assertJsonPath('data.total_unread', 0);
    }

    public function test_message_search(): void
    {
        Sanctum::actingAs($this->user1);
        $convRes = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->user2->id]);
        $convId = $convRes->json('data.id');

        $this->postJson("/api/v1/conversations/{$convId}/messages", ['message' => 'Critical architectural decision made today']);
        $this->postJson("/api/v1/conversations/{$convId}/messages", ['message' => 'Lunch meeting at noon']);

        // Search for "architectural"
        $searchRes = $this->getJson('/api/v1/conversations/search?q=architectural');
        $searchRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Critical architectural decision made today');
    }

    public function test_message_deletion_permissions(): void
    {
        Sanctum::actingAs($this->user1);
        $convRes = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->user2->id]);
        $convId = $convRes->json('data.id');

        $msgRes = $this->postJson("/api/v1/conversations/{$convId}/messages", ['message' => 'Alice message']);
        $msgId = $msgRes->json('data.id');

        // Bob cannot delete Alice's message
        Sanctum::actingAs($this->user2);
        $forbiddenRes = $this->deleteJson("/api/v1/conversations/{$convId}/messages/{$msgId}");
        $forbiddenRes->assertStatus(403);

        // Alice can delete her own message
        Sanctum::actingAs($this->user1);
        $delRes = $this->deleteJson("/api/v1/conversations/{$convId}/messages/{$msgId}");
        $delRes->assertStatus(200);

        $this->assertSoftDeleted('chat_messages', ['id' => $msgId]);
    }
}
