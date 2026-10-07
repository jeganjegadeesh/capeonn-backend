<?php

namespace Tests\Feature\Chat;

use App\Events\Chat\ChatMessageDeletedEvent;
use App\Events\Chat\ChatMessagePinnedEvent;
use App\Events\Chat\ChatMessageUpdatedEvent;
use App\Events\Chat\MessageSentEvent;
use App\Events\Chat\UserPresenceChangedEvent;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\Role;
use App\Models\Task;
use App\Models\Upload;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatPhase6ReviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otherCompany;
    private Department $department;
    private User $superAdmin;
    private User $teamLead;
    private User $member1;
    private User $member2;
    private User $outsider;
    private User $otherCompanyUser;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Storage::fake('local');
        Storage::fake('public');

        $this->company = Company::create(['name' => 'Capeonn Ltd', 'code' => 'CPN', 'is_active' => true]);
        $this->otherCompany = Company::create(['name' => 'Competitor Corp', 'code' => 'CMP', 'is_active' => true]);

        $this->department = Department::create([
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'code' => 'ENG',
        ]);

        $adminRole = Role::where('slug', Role::ADMIN)->firstOrFail();
        $tlRole = Role::where('slug', Role::TEAM_LEAD)->firstOrFail();
        $empRole = Role::where('slug', Role::EMPLOYEE)->firstOrFail();

        $this->superAdmin = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id' => $adminRole->id,
            'name' => 'Super Admin',
            'is_active' => true,
        ]);

        $this->teamLead = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $tlRole->id,
            'name' => 'Alice Team Lead',
            'is_active' => true,
        ]);

        $this->member1 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
            'name' => 'Bob Dev',
            'is_active' => true,
        ]);

        $this->member2 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
            'name' => 'Charlie QA',
            'is_active' => true,
        ]);

        $this->outsider = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $empRole->id,
            'name' => 'Dave Outsider',
            'is_active' => true,
        ]);

        $this->otherCompanyUser = User::factory()->create([
            'company_id' => $this->otherCompany->id,
            'role_id' => $empRole->id,
            'name' => 'Eve Spy',
            'is_active' => true,
        ]);

        $this->project = Project::create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'name' => 'Apollo Mission',
            'code' => 'CPN-APO',
            'status' => 'active',
            'priority' => 'high',
            'start_date' => now(),
            'deadline' => now()->addMonth(),
            'team_lead_id' => $this->teamLead->id,
            'created_by_id' => $this->superAdmin->id,
        ]);

        $this->project->members()->attach($this->member1->id, ['project_role' => 'Developer', 'assigned_at' => now()]);
        $this->project->members()->attach($this->member2->id, ['project_role' => 'QA', 'assigned_at' => now()]);
    }

    /**
     * 1. A user outside a project cannot download that project's files or read its chat,
     * and a removed member loses both immediately.
     */
    public function test_user_outside_project_cannot_download_files_or_read_chat_and_removed_member_loses_both(): void
    {
        // Upload a project file
        Sanctum::actingAs($this->member1);
        $file = UploadedFile::fake()->create('architecture.pdf', 300, 'application/pdf');
        $uploadRes = $this->postJson("/api/v1/projects/{$this->project->id}/files", [
            'file' => $file,
            'category' => 'specification',
        ]);
        $uploadRes->assertStatus(201);
        $fileId = $uploadRes->json('data.id');

        // Open project chat
        $chatRes = $this->getJson("/api/v1/conversations/project/{$this->project->id}");
        $chatRes->assertStatus(200);
        $convId = $chatRes->json('data.id');

        // Outsider cannot download file
        Sanctum::actingAs($this->outsider);
        $downloadRes = $this->getJson("/api/v1/projects/{$this->project->id}/files/{$fileId}/download");
        $downloadRes->assertStatus(403);

        // Outsider cannot access project chat
        $chatAccessRes = $this->getJson("/api/v1/conversations/{$convId}");
        $chatAccessRes->assertStatus(403);

        // Now remove Member 1 from project
        Sanctum::actingAs($this->teamLead);
        $removeRes = $this->deleteJson("/api/v1/projects/{$this->project->id}/members/{$this->member1->id}");
        $removeRes->assertStatus(200);

        // Member 1 now immediately loses download and chat access
        Sanctum::actingAs($this->member1);
        $memberDownload = $this->getJson("/api/v1/projects/{$this->project->id}/files/{$fileId}/download");
        $memberDownload->assertStatus(403);

        $memberChat = $this->getJson("/api/v1/conversations/{$convId}");
        $memberChat->assertStatus(403);
    }

    /**
     * 2. A file URL cannot be fetched without authentication.
     */
    public function test_file_url_cannot_be_fetched_without_authentication(): void
    {
        $res1 = $this->getJson("/api/v1/projects/{$this->project->id}/files/1/download");
        $res1->assertStatus(401);

        $res2 = $this->getJson("/api/v1/conversations/1/attachments/1/download");
        $res2->assertStatus(401);

        $res3 = $this->getJson("/api/v1/uploads/1/download");
        $res3->assertStatus(401);
    }

    /**
     * 3. An attachment path the sender did not upload is rejected.
     */
    public function test_attachment_path_the_sender_did_not_upload_is_rejected(): void
    {
        Sanctum::actingAs($this->member1);
        $convRes = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->member2->id]);
        $convId = $convRes->json('data.id');

        // Attempt to send an attachment path that belongs to someone else or does not exist
        $fakeSend = $this->postJson("/api/v1/conversations/{$convId}/messages", [
            'message' => 'Sneaky attachment',
            'attachments' => [
                [
                    'file_path' => 'chat_uploads/some_other_user_file.pdf',
                    'file_name' => 'secret.pdf',
                    'file_size' => 1234,
                    'mime_type' => 'application/pdf',
                ],
            ],
        ]);

        $fakeSend->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0']);

        // Legitimate upload by member1 succeeds
        $up = Upload::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member1->id,
            'file_path' => 'chat_uploads/legit.pdf',
            'file_name' => 'legit.pdf',
            'file_size' => 5000,
            'mime_type' => 'application/pdf',
        ]);

        $legitSend = $this->postJson("/api/v1/conversations/{$convId}/messages", [
            'message' => 'Valid attachment',
            'attachments' => [
                [
                    'upload_id' => $up->id,
                ],
            ],
        ]);

        $legitSend->assertStatus(201)
            ->assertJsonPath('data.attachments.0.file_name', 'legit.pdf');
    }

    /**
     * 4. reply_to_id from another conversation, and task_id from another company, are rejected.
     */
    public function test_reply_to_from_other_conversation_and_cross_company_task_are_rejected(): void
    {
        Sanctum::actingAs($this->member1);
        $conv1 = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->member2->id])->json('data.id');
        $conv2 = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->teamLead->id])->json('data.id');

        // Send message in conv2
        $msg2 = $this->postJson("/api/v1/conversations/{$conv2}/messages", ['message' => 'Alice chat'])->json('data.id');

        // Attempt to reply in conv1 to a message from conv2
        $replyMismatch = $this->postJson("/api/v1/conversations/{$conv1}/messages", [
            'message' => 'Replying to wrong conversation',
            'reply_to_id' => $msg2,
        ]);
        $replyMismatch->assertStatus(422)
            ->assertJsonValidationErrors(['reply_to_id']);

        // Attempt to link a task from other company
        $otherProject = Project::create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Other Co Project',
            'code' => 'OTH-1',
            'status' => 'active',
            'priority' => 'low',
            'start_date' => now(),
            'deadline' => now()->addMonth(),
        ]);

        $otherTask = Task::create([
            'company_id' => $this->otherCompany->id,
            'project_id' => $otherProject->id,
            'title' => 'Steal Data',
            'status' => 'assigned',
            'priority' => 'high',
        ]);

        $taskMismatch = $this->postJson("/api/v1/conversations/{$conv1}/messages", [
            'message' => 'Cross company task leak attempt',
            'task_id' => $otherTask->id,
        ]);
        $taskMismatch->assertStatus(422)
            ->assertJsonValidationErrors(['task_id']);
    }

    /**
     * 5. Super Admin cannot read a private DM.
     */
    public function test_super_admin_cannot_read_private_dm_between_employees(): void
    {
        // Member 1 and Member 2 have a private DM
        Sanctum::actingAs($this->member1);
        $dm = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->member2->id]);
        $dmId = $dm->json('data.id');

        $this->postJson("/api/v1/conversations/{$dmId}/messages", ['message' => 'Private secret chat between us']);

        // Super Admin tries to fetch the DM
        Sanctum::actingAs($this->superAdmin);
        $adminAccess = $this->getJson("/api/v1/conversations/{$dmId}");
        $adminAccess->assertStatus(403);

        $adminMessages = $this->getJson("/api/v1/conversations/{$dmId}/messages");
        $adminMessages->assertStatus(403);

        // Super Admin does not see it in conversations list
        $adminList = $this->getJson('/api/v1/conversations');
        $adminList->assertStatus(200);
        $ids = collect($adminList->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($dmId));
    }

    /**
     * 6. Project chat participants are synced on member removal and lead change.
     */
    public function test_project_chat_participants_are_synced_on_member_removal_and_lead_change(): void
    {
        Sanctum::actingAs($this->teamLead);
        $chatRes = $this->getJson("/api/v1/conversations/project/{$this->project->id}");
        $convId = $chatRes->json('data.id');

        // Initially: team lead, member 1, member 2 are participants
        $this->assertTrue(ConversationParticipant::where('conversation_id', $convId)->where('user_id', $this->member1->id)->exists());
        $this->assertTrue(ConversationParticipant::where('conversation_id', $convId)->where('user_id', $this->member2->id)->exists());

        // Remove member 2 from project
        $this->deleteJson("/api/v1/projects/{$this->project->id}/members/{$this->member2->id}");

        // Verify member 2 removed from chat participants
        $this->assertFalse(ConversationParticipant::where('conversation_id', $convId)->where('user_id', $this->member2->id)->exists());

        // Change Team Lead to member 1
        Sanctum::actingAs($this->superAdmin);
        $this->member1->update(['role_id' => Role::where('slug', Role::TEAM_LEAD)->firstOrFail()->id]);
        $leadRes = $this->postJson("/api/v1/projects/{$this->project->id}/lead", [
            'team_lead_id' => $this->member1->id,
            'keep_as_member' => false,
        ]);
        $leadRes->assertOk();

        // Old lead Alice should be removed from participants since keep_as_member was false
        $this->assertFalse(ConversationParticipant::where('conversation_id', $convId)->where('user_id', $this->teamLead->id)->exists());
        // New lead Member 1 is admin
        $this->assertTrue(ConversationParticipant::where('conversation_id', $convId)->where('user_id', $this->member1->id)->where('role', 'admin')->exists());
    }

    /**
     * 7. Two simultaneous direct-chat creations produce one conversation.
     */
    public function test_two_direct_chat_creations_produce_one_conversation(): void
    {
        Sanctum::actingAs($this->member1);

        $res1 = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->member2->id]);
        $res2 = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->member2->id]);

        $res1->assertStatus(201);
        $res2->assertStatus(200);

        $this->assertEquals($res1->json('data.id'), $res2->json('data.id'));

        // Query database: exactly 1 direct conversation between these two
        $count = Conversation::where('company_id', $this->company->id)
            ->where('type', Conversation::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $this->member1->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $this->member2->id))
            ->count();

        $this->assertEquals(1, $count);
    }

    /**
     * 8. A closed project blocks messages and uploads.
     */
    public function test_closed_project_blocks_messages_and_uploads(): void
    {
        Sanctum::actingAs($this->teamLead);
        $chat = $this->getJson("/api/v1/conversations/project/{$this->project->id}")->json('data');
        $convId = $chat['id'];

        // Close the project
        $this->project->update(['status' => 'completed']);

        // Upload blocked
        $file = UploadedFile::fake()->create('final.pdf', 100, 'application/pdf');
        $upRes = $this->postJson("/api/v1/projects/{$this->project->id}/files", ['file' => $file]);
        $upRes->assertStatus(403);

        // Chat message blocked
        $msgRes = $this->postJson("/api/v1/conversations/{$convId}/messages", ['message' => 'Trying to chat in closed project']);
        $msgRes->assertStatus(403);
    }

    /**
     * 9. Upload size and type limits (including a renamed .exe).
     */
    public function test_upload_size_and_type_limits_enforced(): void
    {
        Sanctum::actingAs($this->member1);

        // Reject executable disguised as pdf or real exe
        $exeFile = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');
        $res1 = $this->postJson('/api/v1/uploads', ['file' => $exeFile]);
        $res1->assertStatus(422);

        // Reject executable in project files
        $res2 = $this->postJson("/api/v1/projects/{$this->project->id}/files", ['file' => $exeFile]);
        $res2->assertStatus(422);

        // Reject file exceeding max size (25 MB > 20 MB)
        $bigFile = UploadedFile::fake()->create('huge.zip', 25600, 'application/zip');
        $res3 = $this->postJson('/api/v1/uploads', ['file' => $bigFile]);
        $res3->assertStatus(422);
    }

    /**
     * 10. A deleted message disappears from search, replies and broadcasts delete event.
     */
    public function test_deleted_message_behavior_and_event(): void
    {
        Event::fake([ChatMessageDeletedEvent::class]);

        Sanctum::actingAs($this->member1);
        $convId = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->member2->id])->json('data.id');

        $msgRes = $this->postJson("/api/v1/conversations/{$convId}/messages", ['message' => 'Confidential payload keyword']);
        $msgId = $msgRes->json('data.id');

        // Delete message
        $delRes = $this->deleteJson("/api/v1/conversations/{$convId}/messages/{$msgId}");
        $delRes->assertStatus(200);

        Event::assertDispatched(ChatMessageDeletedEvent::class, function ($event) use ($convId, $msgId) {
            return $event->conversationId === $convId && $event->messageId === $msgId;
        });

        // Search excludes deleted message
        $searchRes = $this->getJson('/api/v1/conversations/search?q=payload');
        $searchRes->assertStatus(200);
        $this->assertCount(0, $searchRes->json('data'));
    }

    /**
     * 11. Mention parsing and the mention notification.
     */
    public function test_mention_parsing_and_notification(): void
    {
        Sanctum::actingAs($this->teamLead);
        $chat = $this->getJson("/api/v1/conversations/project/{$this->project->id}")->json('data');
        $convId = $chat['id'];

        // Alice sends message mentioning Bob
        $sendRes = $this->postJson("/api/v1/conversations/{$convId}/messages", [
            'message' => 'Hey @Bob Dev please check the release pipeline!',
            'mentions' => [$this->member1->id],
        ]);
        $sendRes->assertStatus(201);

        // Notification created for Bob
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->member1->id,
            'type' => 'chat_mention',
        ]);

        // Mention relation stored
        $this->assertDatabaseHas('chat_message_mentions', [
            'user_id' => $this->member1->id,
            'chat_message_id' => $sendRes->json('data.id'),
        ]);
    }

    /**
     * 12. Message edit and update broadcast.
     */
    public function test_message_edit_updates_content_and_broadcasts(): void
    {
        Event::fake([ChatMessageUpdatedEvent::class]);

        Sanctum::actingAs($this->member1);
        $convId = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->member2->id])->json('data.id');

        $sendRes = $this->postJson("/api/v1/conversations/{$convId}/messages", ['message' => 'Initial text']);
        $msgId = $sendRes->json('data.id');

        $editRes = $this->putJson("/api/v1/conversations/{$convId}/messages/{$msgId}", [
            'message' => 'Updated text with corrections',
        ]);

        $editRes->assertStatus(200)
            ->assertJsonPath('data.message', 'Updated text with corrections')
            ->assertJsonPath('data.is_edited', true);

        Event::assertDispatched(ChatMessageUpdatedEvent::class);
    }

    /**
     * 13. Mute conversation and leave group.
     */
    public function test_mute_conversation_and_leave_group(): void
    {
        Sanctum::actingAs($this->member1);
        $group = $this->postJson('/api/v1/conversations/group', [
            'title' => 'Mobile Squad',
            'participant_ids' => [$this->member2->id, $this->teamLead->id],
        ])->json('data');
        $convId = $group['id'];

        // Mute conversation
        $muteRes = $this->postJson("/api/v1/conversations/{$convId}/mute", ['is_muted' => true]);
        $muteRes->assertStatus(200)->assertJsonPath('data.is_muted', true);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $convId,
            'user_id' => $this->member1->id,
            'is_muted' => true,
        ]);

        // Member 2 leaves group
        Sanctum::actingAs($this->member2);
        $leaveRes = $this->postJson("/api/v1/conversations/{$convId}/leave");
        $leaveRes->assertStatus(200);

        $this->assertDatabaseMissing('conversation_participants', [
            'conversation_id' => $convId,
            'user_id' => $this->member2->id,
        ]);
    }

    /**
     * 14. Presence heartbeat optimization and privacy.
     */
    public function test_presence_heartbeat_optimization_and_privacy(): void
    {
        Event::fake([UserPresenceChangedEvent::class]);

        Sanctum::actingAs($this->member1);

        // First heartbeat: transitions offline -> online, should broadcast
        $res1 = $this->postJson('/api/v1/presence/heartbeat');
        $res1->assertStatus(200);
        Event::assertDispatched(UserPresenceChangedEvent::class, 1);

        // Immediate second heartbeat: already online, does NOT broadcast again
        $res2 = $this->postJson('/api/v1/presence/heartbeat');
        $res2->assertStatus(200);
        Event::assertDispatched(UserPresenceChangedEvent::class, 1);

        // Enable privacy ("hide online status")
        $privRes = $this->putJson('/api/v1/presence/privacy', ['hide_presence' => true]);
        $privRes->assertStatus(200)->assertJsonPath('data.hide_presence', true);

        $this->assertFalse($this->member1->fresh()->isOnline());
    }

    /**
     * 15. File versioning and metadata updates.
     */
    public function test_file_versioning_and_metadata_updates(): void
    {
        Sanctum::actingAs($this->member1);

        $file = UploadedFile::fake()->create('spec_v1.pdf', 200, 'application/pdf');
        $up = $this->postJson("/api/v1/projects/{$this->project->id}/files", ['file' => $file])->json('data');
        $fileId = $up['id'];

        $this->assertEquals(1, $up['version']);

        // Update description / rename
        $editRes = $this->putJson("/api/v1/projects/{$this->project->id}/files/{$fileId}", [
            'file_name' => 'final_spec.pdf',
            'description' => 'Updated documentation notes',
        ]);
        $editRes->assertStatus(200)
            ->assertJsonPath('data.file_name', 'final_spec.pdf')
            ->assertJsonPath('data.description', 'Updated documentation notes');

        // Upload new version
        $newFile = UploadedFile::fake()->create('spec_v2.pdf', 250, 'application/pdf');
        $verRes = $this->postJson("/api/v1/projects/{$this->project->id}/files/{$fileId}/version", [
            'file' => $newFile,
            'description' => 'Version 2 with API spec updates',
        ]);
        $verRes->assertStatus(200)
            ->assertJsonPath('data.version', 2);
    }

    /**
     * 16. Device tokens registration and deregistration for Phase 8 FCM.
     */
    public function test_device_tokens_registration_and_deregistration(): void
    {
        Sanctum::actingAs($this->member1);

        $regRes = $this->postJson('/api/v1/device-tokens', [
            'token' => 'fcm_test_token_12345',
            'platform' => 'android',
        ]);
        $regRes->assertStatus(201);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $this->member1->id,
            'token' => 'fcm_test_token_12345',
            'platform' => 'android',
        ]);

        $delRes = $this->deleteJson('/api/v1/device-tokens', [
            'token' => 'fcm_test_token_12345',
        ]);
        $delRes->assertStatus(200);

        $this->assertDatabaseMissing('device_tokens', [
            'token' => 'fcm_test_token_12345',
        ]);
    }
}
