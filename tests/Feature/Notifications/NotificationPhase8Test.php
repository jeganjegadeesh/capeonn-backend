<?php

namespace Tests\Feature\Notifications;

use App\Console\Commands\CheckProjectDeadlinesCommand;
use App\Console\Commands\CheckTaskDeadlinesCommand;
use App\Events\Notifications\NotificationBroadcastEvent;
use App\Events\Projects\ProjectDeadlineApproachingEvent;
use App\Events\Projects\ProjectOverdueEvent;
use App\Events\Tasks\TaskAssignedEvent;
use App\Events\Tasks\TaskChangesRequestedEvent;
use App\Events\Tasks\TaskCompletedEvent;
use App\Events\Tasks\TaskDueSoonEvent;
use App\Events\Tasks\TaskOverdueEvent;
use App\Events\Tasks\TaskSubmittedForReviewEvent;
use App\Jobs\SendPushNotificationJob;
use App\Models\Company;
use App\Models\Department;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\FcmNotificationService;
use App\Services\NotificationService;
use App\Services\NotificationTypeRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationPhase8Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $department;
    private User $lead;
    private User $manager;
    private User $employee;
    private Project $project;
    private Task $task;
    private ?string $tempCredentialsPath = null;

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

        $tlRole  = Role::where('slug', Role::TEAM_LEAD)->firstOrFail();
        $mgrRole = Role::where('slug', Role::MANAGER)->firstOrFail();
        $empRole = Role::where('slug', Role::EMPLOYEE)->firstOrFail();

        $this->lead = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id'    => $tlRole->id,
            'name'       => 'Lead User',
            'is_active'  => true,
        ]);

        $this->manager = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id'    => $mgrRole->id,
            'name'       => 'Manager User',
            'is_active'  => true,
        ]);

        $this->employee = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id'    => $empRole->id,
            'name'       => 'Employee One',
            'is_active'  => true,
        ]);

        $this->project = Project::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->department->id,
            'name'          => 'Core Platform',
            'status'        => Project::STATUS_ACTIVE,
            'team_lead_id'  => $this->lead->id,
            'manager_id'    => $this->manager->id,
            'created_by_id' => $this->lead->id,
            'start_date'    => now()->subDays(5)->toDateString(),
            'deadline'      => now()->addDays(10)->toDateString(),
        ]);

        $this->task = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'created_by_id'  => $this->lead->id,
            'assigned_to_id' => $this->employee->id,
            'title'          => 'Implement Push Notifications',
            'status'         => Task::STATUS_ASSIGNED,
            'priority'       => Task::PRIORITY_HIGH,
            'due_date'       => now()->addDays(3)->toDateString(),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->tempCredentialsPath && file_exists($this->tempCredentialsPath)) {
            @unlink($this->tempCredentialsPath);
        }
        parent::tearDown();
    }

    private function setupFakeFcmCredentials(): void
    {
        $privKey = "-----BEGIN PRIVATE KEY-----\n"
            . "MIICdgIBADANBgkqhkiG9w0BAQEFAASCAmAwggJcAgEAAoGBAKbzjKjoGSi8Wj3D\n"
            . "pZQUTwJud5Y3t162jKP3RHaIpeA4qnM+M6NosgHH7mEKGvNpSLvrGiEPq6Asc7SI\n"
            . "SdXjfEnt/iUD4b/5ku7/snJ7Ce4cEkgCyZtxvfcMFjNTD3fyncbvG4r8d4zm9qe0\n"
            . "+tmfVTFgT3cGWoD23tUEU1g8NbcPAgMBAAECgYBHkfsH4c//TZemCLxv2bDxSugL\n"
            . "RsFeYgWyuOrpobTyhXhUxSKf997vsoT3prxdAxKCNMxTxgL+PHAxwYddoRMDcTdG\n"
            . "CGsRJ0WN6TXZzkDkAlD+mbkuH20kdBsZcZAXDy4n//6uoW8XRXUvSaIFLnWYeii9\n"
            . "0XiN/btCCz5JuSEBSQJBANDM6dA2fd6AFLQiMmElzM8WMJ7O8t6dahT4Ugn3X/CH\n"
            . "HGtC5kmViEtQjtZCjEV+tQOByWhtb1iNJqQNCKpfb20CQQDMsN57RQtbrhKumB96\n"
            . "vFFPRZR2sB13Xqii0S4HhQx4UGN6GXEYxnBXtL4LOEfzyT8o6wTTSpJEb8VVZq0+\n"
            . "6WbrAkBtJ8LP87zHleO86+e0e8NfO3V9gCjdkiOEBPE2fpksMCX+OQIU09Rlsx6J\n"
            . "Ck80gXH1/GKjchp0i43400uFbdDdAkBr8aYSOdIIkh72BfEr5521gUsO4EdZyQiD\n"
            . "fG6atpZh6HrdTAdxekyKZB2IizCepnVxXacYyg2im5LkO5aHQ8zrAkEAzXPWyt2B\n"
            . "U4EGYt5oho2+wFazfkGWsigXvfDBq7JbxTUjJcL9Ba3vMvd9Fxr+TUiTwR3DSTkf\n"
            . "A9KLJm21aKT/Sw==\n"
            . "-----END PRIVATE KEY-----";

        $fakeJson = json_encode([
            'type'         => 'service_account',
            'project_id'   => 'capeonn-prod',
            'private_key'  => $privKey,
            'client_email' => 'firebase-adminsdk@capeonn-prod.iam.gserviceaccount.com',
        ]);

        $this->tempCredentialsPath = tempnam(sys_get_temp_dir(), 'fcm_') . '.json';
        file_put_contents($this->tempCredentialsPath, $fakeJson);

        config([
            'services.fcm.project_id'       => 'capeonn-prod',
            'services.fcm.credentials_path' => $this->tempCredentialsPath,
        ]);
        Cache::flush();
    }

    // =========================================================================
    // 1. DEVICE TOKEN LIFECYCLE, VALIDATION, AUTH, & USER TRANSFER
    // =========================================================================

    public function test_user_can_register_device_token_for_all_platforms(): void
    {
        Sanctum::actingAs($this->employee);

        $platforms = ['android', 'ios', 'web', 'windows'];

        foreach ($platforms as $idx => $platform) {
            $token = "token_sample_{$platform}_{$idx}_" . str_repeat('a', 25);
            $response = $this->postJson('/api/v1/device-tokens', [
                'token'       => $token,
                'platform'    => $platform,
                'device_name' => ucfirst($platform) . ' Device',
            ]);

            $response->assertStatus(201)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.platform', $platform)
                ->assertJsonPath('data.token', $token);

            $this->assertDatabaseHas('device_tokens', [
                'user_id'     => $this->employee->id,
                'token'       => $token,
                'platform'    => $platform,
                'device_name' => ucfirst($platform) . ' Device',
            ]);
        }
    }

    public function test_device_tokens_validation_and_authentication_guards(): void
    {
        // 1. Unauthenticated call returns 401
        $unauthRes = $this->getJson('/api/v1/device-tokens');
        $unauthRes->assertStatus(401);

        Sanctum::actingAs($this->employee);

        // 2. Invalid platform returns 422
        $invalidPlatformRes = $this->postJson('/api/v1/device-tokens', [
            'token'    => 'valid_token_string_over_20_chars',
            'platform' => 'blackberry',
        ]);
        $invalidPlatformRes->assertStatus(422)
            ->assertJsonValidationErrors(['platform']);
    }

    public function test_device_token_moves_to_new_user_when_registered_on_same_device(): void
    {
        $sharedDeviceToken = 'shared_hardware_device_token_' . str_repeat('x', 20);

        // Registered originally by lead
        Sanctum::actingAs($this->lead);
        $this->postJson('/api/v1/device-tokens', [
            'token'    => $sharedDeviceToken,
            'platform' => 'android',
        ])->assertStatus(201);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $this->lead->id,
            'token'   => $sharedDeviceToken,
        ]);

        // Same hardware device token now registered by employee
        Sanctum::actingAs($this->employee);
        $this->postJson('/api/v1/device-tokens', [
            'token'    => $sharedDeviceToken,
            'platform' => 'android',
        ])->assertStatus(201);

        // Token now belongs exclusively to the new user
        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $this->employee->id,
            'token'   => $sharedDeviceToken,
        ]);
        $this->assertDatabaseMissing('device_tokens', [
            'user_id' => $this->lead->id,
            'token'   => $sharedDeviceToken,
        ]);
    }

    public function test_user_cannot_delete_another_users_device_token(): void
    {
        $leadToken = 'lead_secure_token_' . str_repeat('z', 20);
        DeviceToken::create([
            'user_id'  => $this->lead->id,
            'token'    => $leadToken,
            'platform' => 'ios',
        ]);

        Sanctum::actingAs($this->employee);

        // Employee tries to delete Lead's token
        $res = $this->deleteJson('/api/v1/device-tokens', [
            'token' => $leadToken,
        ]);
        $res->assertOk();

        // Lead token remains intact
        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $this->lead->id,
            'token'   => $leadToken,
        ]);
    }

    public function test_logout_removes_device_token_from_server(): void
    {
        $token = 'logout_test_token_' . str_repeat('k', 20);
        DeviceToken::create([
            'user_id'  => $this->employee->id,
            'token'    => $token,
            'platform' => 'android',
        ]);

        Sanctum::actingAs($this->employee);

        $this->postJson('/api/v1/auth/logout', [
            'device_token' => $token,
        ])->assertOk();

        $this->assertDatabaseMissing('device_tokens', [
            'user_id' => $this->employee->id,
            'token'   => $token,
        ]);
    }

    public function test_deactivating_user_removes_all_their_device_tokens(): void
    {
        DeviceToken::create([
            'user_id'  => $this->employee->id,
            'token'    => 'token_emp_1_' . str_repeat('1', 20),
            'platform' => 'android',
        ]);
        DeviceToken::create([
            'user_id'  => $this->employee->id,
            'token'    => 'token_emp_2_' . str_repeat('2', 20),
            'platform' => 'web',
        ]);

        $adminRole = Role::where('slug', Role::ADMIN)->firstOrFail();
        $admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id'    => $adminRole->id,
            'is_active'  => true,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/employees/{$this->employee->id}", [
            'is_active' => false,
        ])->assertOk();

        $this->assertDatabaseMissing('device_tokens', [
            'user_id' => $this->employee->id,
        ]);
    }

    // =========================================================================
    // 2. PREFERENCES, OPT-OUT SEPARATION, & MANDATORY TYPES
    // =========================================================================

    public function test_user_can_get_and_update_notification_preferences(): void
    {
        Sanctum::actingAs($this->employee);

        $getRes = $this->getJson('/api/v1/notifications/preferences');
        $getRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.push_enabled', true)
            ->assertJsonPath('data.task_alerts', true)
            ->assertJsonPath('data.quiet_hours_enabled', false);

        $updateRes = $this->putJson('/api/v1/notifications/preferences', [
            'push_enabled'        => false,
            'chat_alerts'         => false,
            'quiet_hours_enabled' => true,
            'quiet_hours_start'   => '22:00',
            'quiet_hours_end'     => '08:00',
            'hide_previews'       => true,
        ]);

        $updateRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.push_enabled', false)
            ->assertJsonPath('data.chat_alerts', false)
            ->assertJsonPath('data.quiet_hours_enabled', true)
            ->assertJsonPath('data.hide_previews', true);

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id'             => $this->employee->id,
            'push_enabled'        => false,
            'chat_alerts'         => false,
            'quiet_hours_enabled' => true,
            'hide_previews'       => true,
        ]);
    }

    public function test_notification_service_creates_inbox_row_when_opted_out_but_skips_push(): void
    {
        Queue::fake();

        // Disable task_alerts for employee
        UserNotificationPreference::updateOrCreate(
            ['user_id' => $this->employee->id],
            ['task_alerts' => false, 'push_enabled' => true]
        );

        $service = app(NotificationService::class);
        $result = $service->notifyUser(
            $this->employee->id,
            'task_comment',
            'New Task Comment',
            'Discussion on task specifications',
            ['task_id' => $this->task->id]
        );

        // 1. Inbox database record IS created!
        $this->assertNotNull($result);
        $this->assertDatabaseHas('notifications', [
            'id'      => $result->id,
            'user_id' => $this->employee->id,
            'type'    => 'task_comment',
        ]);

        // 2. FCM Push job was NOT dispatched because category is disabled
        Queue::assertNotPushed(SendPushNotificationJob::class);

        // 3. Delivery audit log records skipped status
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $result->id,
            'user_id'         => $this->employee->id,
            'channel'         => 'fcm',
            'status'          => 'skipped',
        ]);
    }

    public function test_mandatory_notification_types_ignore_opt_out_preferences(): void
    {
        Queue::fake();

        // User disabled all push and alerts
        UserNotificationPreference::updateOrCreate(
            ['user_id' => $this->employee->id],
            [
                'push_enabled'    => false,
                'task_alerts'     => false,
                'deadline_alerts' => false,
            ]
        );

        $service = app(NotificationService::class);

        // Mandatory types: assigned, review, changes requested, completed, overdue
        $mandatoryTypes = [
            'task_assigned',
            'task_review',
            'task_changes_requested',
            'task_completed',
            'task_overdue',
        ];

        foreach ($mandatoryTypes as $type) {
            $n = $service->notifyUser(
                $this->employee->id,
                $type,
                'Mandatory Alert',
                'This alert cannot be suppressed',
                ['task_id' => $this->task->id]
            );

            $this->assertNotNull($n);
            $this->assertDatabaseHas('notifications', [
                'id'   => $n->id,
                'type' => $type,
            ]);
        }

        // Push jobs were dispatched for all 5 mandatory notifications despite user opt-out
        Queue::assertPushed(SendPushNotificationJob::class, 5);
    }

    public function test_notification_service_uses_users_own_company_even_if_different_id_passed(): void
    {
        $otherCompany = Company::create(['name' => 'Other Inc', 'code' => 'OTH', 'is_active' => true]);

        $service = app(NotificationService::class);
        $notification = $service->notifyUser(
            $this->employee->id,
            'task_assigned',
            'Task Assignment',
            'Assigned to task',
            ['task_id' => $this->task->id],
            $otherCompany->id // Pass mismatched company ID
        );

        $this->assertNotNull($notification);
        // Saved under employee's actual company_id, not otherCompany->id
        $this->assertEquals($this->employee->company_id, $notification->company_id);
    }

    // =========================================================================
    // 3. FCM V1 DELIVERY WITH HTTP::FAKE & CREDENTIALS
    // =========================================================================

    public function test_fcm_v1_successful_delivery(): void
    {
        $this->setupFakeFcmCredentials();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'mock_token_123']),
            'https://fcm.googleapis.com/v1/projects/capeonn-prod/messages:send' => Http::response([
                'name' => 'projects/capeonn-prod/messages/msg_abc_001',
            ], 200),
        ]);

        $fcmService = app(FcmNotificationService::class);
        $tokens = ['test_valid_device_token_' . str_repeat('7', 20)];

        $res = $fcmService->sendToTokens($tokens, 'Test Title', 'Test Message', ['sample' => 'data']);

        $this->assertTrue($res['configured']);
        $this->assertEquals(1, $res['sent']);
        $this->assertEquals(0, $res['failed']);
        $this->assertEquals(0, $res['pruned']);
    }

    public function test_fcm_v1_unregistered_response_prunes_token(): void
    {
        $this->setupFakeFcmCredentials();

        $tokenToPrune = 'unregistered_device_token_' . str_repeat('9', 20);
        DeviceToken::create([
            'user_id'  => $this->employee->id,
            'token'    => $tokenToPrune,
            'platform' => 'android',
        ]);

        $this->assertDatabaseHas('device_tokens', ['token' => $tokenToPrune]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'mock_token_123']),
            'https://fcm.googleapis.com/v1/projects/capeonn-prod/messages:send' => Http::response([
                'error' => [
                    'code'    => 404,
                    'message' => 'Requested entity was not found.',
                    'status'  => 'NOT_FOUND',
                    'details' => [
                        [
                            '@type'     => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                            'errorCode' => 'UNREGISTERED',
                        ],
                    ],
                ],
            ], 404),
        ]);

        $fcmService = app(FcmNotificationService::class);
        $res = $fcmService->sendToTokens([$tokenToPrune], 'Title', 'Message');

        $this->assertEquals(0, $res['sent']);
        $this->assertEquals(1, $res['failed']);
        $this->assertEquals(1, $res['pruned']);

        // Assert token was automatically deleted from database
        $this->assertDatabaseMissing('device_tokens', ['token' => $tokenToPrune]);
    }

    public function test_fcm_v1_server_error_counts_as_failed_without_pruning_token(): void
    {
        $this->setupFakeFcmCredentials();

        $goodToken = 'server_error_token_' . str_repeat('5', 20);
        DeviceToken::create([
            'user_id'  => $this->employee->id,
            'token'    => $goodToken,
            'platform' => 'android',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'mock_token_123']),
            'https://fcm.googleapis.com/v1/projects/capeonn-prod/messages:send' => Http::response([
                'error' => ['code' => 500, 'message' => 'Internal error', 'status' => 'INTERNAL'],
            ], 500),
        ]);

        $fcmService = app(FcmNotificationService::class);
        $res = $fcmService->sendToTokens([$goodToken], 'Title', 'Message');

        $this->assertEquals(0, $res['sent']);
        $this->assertEquals(1, $res['failed']);
        $this->assertEquals(0, $res['pruned']);

        // Token is kept (temporary Google outage)
        $this->assertDatabaseHas('device_tokens', ['token' => $goodToken]);
    }

    public function test_oauth_access_token_is_cached_across_multiple_sends(): void
    {
        $this->setupFakeFcmCredentials();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'cached_oauth_token_val']),
            'https://fcm.googleapis.com/v1/projects/capeonn-prod/messages:send' => Http::response(['name' => 'msg/1']),
        ]);

        $fcmService = app(FcmNotificationService::class);
        $token = 'test_token_' . str_repeat('6', 20);

        // Send 1
        $fcmService->sendToTokens([$token], 'Title 1', 'Message 1');
        // Send 2
        $fcmService->sendToTokens([$token], 'Title 2', 'Message 2');

        // OAuth token endpoint was only invoked ONCE due to Cache::remember
        Http::assertSentCount(3); // 1 oauth call + 2 fcm send calls
    }

    public function test_missing_fcm_project_id_fails_loudly_without_sending(): void
    {
        config(['services.fcm.project_id' => null, 'services.fcm.credentials_path' => null]);

        $fcmService = app(FcmNotificationService::class);
        $this->assertFalse($fcmService->isConfigured());

        $token = 'some_device_token_' . str_repeat('3', 20);
        $res = $fcmService->sendToTokens([$token], 'Title', 'Body');

        $this->assertFalse($res['configured']);
        $this->assertEquals(0, $res['sent']);
        $this->assertEquals(1, $res['failed']);
        $this->assertStringContainsString('not configured', $res['error'] ?? '');
    }

    // =========================================================================
    // 4. TEST PUSH ENDPOINT
    // =========================================================================

    public function test_test_push_endpoint_returns_422_when_fcm_unconfigured(): void
    {
        Sanctum::actingAs($this->employee);

        // No token registered
        $noTokenRes = $this->postJson('/api/v1/notifications/test-push');
        $noTokenRes->assertStatus(422)
            ->assertJsonPath('success', false);

        // Register token
        DeviceToken::create([
            'user_id'  => $this->employee->id,
            'token'    => 'test_push_token_' . str_repeat('4', 20),
            'platform' => 'android',
        ]);

        // Push not configured on server (empty credentials)
        config(['services.fcm.project_id' => null, 'services.fcm.credentials_path' => null]);

        $withTokenRes = $this->postJson('/api/v1/notifications/test-push');
        $withTokenRes->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.configured', false);
    }

    public function test_test_push_endpoint_returns_success_with_actual_sent_count_when_configured(): void
    {
        Sanctum::actingAs($this->employee);
        $this->setupFakeFcmCredentials();

        DeviceToken::create([
            'user_id'  => $this->employee->id,
            'token'    => 'test_push_token_configured_' . str_repeat('8', 20),
            'platform' => 'android',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'mock_token']),
            'https://fcm.googleapis.com/v1/projects/capeonn-prod/messages:send' => Http::response(['name' => 'msg/test']),
        ]);

        $res = $this->postJson('/api/v1/notifications/test-push');
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.failed', 0);
    }

    // =========================================================================
    // 5. DEADLINE COMMANDS & IDEMPOTENT DEDUPLICATION IN ASIA/KOLKATA
    // =========================================================================

    public function test_deadline_and_overdue_notifications_are_deduplicated_same_day(): void
    {
        // 1. Task Overdue event fired twice
        TaskOverdueEvent::dispatch($this->task, 2);
        TaskOverdueEvent::dispatch($this->task, 2);

        $count = Notification::where('user_id', $this->employee->id)
            ->where('type', 'task_overdue')
            ->count();
        $this->assertEquals(1, $count);

        // 2. Task Due Soon event fired twice
        TaskDueSoonEvent::dispatch($this->task, 1);
        TaskDueSoonEvent::dispatch($this->task, 1);

        $dueSoonCount = Notification::where('user_id', $this->employee->id)
            ->where('type', 'task_due_soon')
            ->count();
        $this->assertEquals(1, $dueSoonCount);

        // 3. Project Overdue event fired twice
        ProjectOverdueEvent::dispatch($this->project, 3);
        ProjectOverdueEvent::dispatch($this->project, 3);

        $projCount = Notification::where('user_id', $this->lead->id)
            ->where('type', 'project_overdue')
            ->count();
        $this->assertEquals(1, $projCount);

        // 4. Project Deadline Approaching fired twice
        ProjectDeadlineApproachingEvent::dispatch($this->project, 7);
        ProjectDeadlineApproachingEvent::dispatch($this->project, 7);

        $projAppCount = Notification::where('user_id', $this->lead->id)
            ->where('type', 'project_deadline_approaching')
            ->count();
        $this->assertEquals(1, $projAppCount);
    }

    public function test_deadline_commands_run_twice_in_one_day_creates_one_notification_per_task_and_project(): void
    {
        $kolkataToday = \Carbon\Carbon::today('Asia/Kolkata');

        // Set task due yesterday (overdue)
        $this->task->update([
            'due_date' => $kolkataToday->copy()->subDay()->toDateString(),
            'status'   => Task::STATUS_IN_PROGRESS,
        ]);

        // Run CheckTaskDeadlinesCommand twice
        $this->artisan('capeonn:check-task-deadlines')->assertSuccessful();
        $this->artisan('capeonn:check-task-deadlines')->assertSuccessful();

        $taskNotifs = Notification::where('user_id', $this->employee->id)
            ->where('type', 'task_overdue')
            ->count();
        $this->assertEquals(1, $taskNotifs);

        // Set project deadline yesterday (overdue)
        $this->project->update([
            'deadline' => $kolkataToday->copy()->subDay()->toDateString(),
            'status'   => Project::STATUS_ACTIVE,
        ]);

        // Run CheckProjectDeadlinesCommand twice
        $this->artisan('capeonn:check-project-deadlines')->assertSuccessful();
        $this->artisan('capeonn:check-project-deadlines')->assertSuccessful();

        $projNotifs = Notification::where('user_id', $this->lead->id)
            ->where('type', 'project_overdue')
            ->count();
        $this->assertEquals(1, $projNotifs);
    }

    public function test_completed_tasks_and_closed_projects_are_skipped_by_deadline_commands(): void
    {
        $yesterday = \Carbon\Carbon::yesterday('Asia/Kolkata')->toDateString();

        $completedTask = Task::create([
            'company_id'     => $this->company->id,
            'project_id'     => $this->project->id,
            'created_by_id'  => $this->lead->id,
            'assigned_to_id' => $this->employee->id,
            'title'          => 'Completed Task',
            'status'         => Task::STATUS_COMPLETED,
            'due_date'       => $yesterday,
        ]);

        $closedProject = Project::create([
            'company_id'    => $this->company->id,
            'department_id' => $this->department->id,
            'name'          => 'Archived Project',
            'status'        => Project::STATUS_ARCHIVED,
            'team_lead_id'  => $this->lead->id,
            'deadline'      => $yesterday,
        ]);

        $this->artisan('capeonn:check-task-deadlines')->assertSuccessful();
        $this->artisan('capeonn:check-project-deadlines')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', [
            'data->task_id' => $completedTask->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'data->project_id' => $closedProject->id,
        ]);
    }

    // =========================================================================
    // 6. LISTENER RECIPIENTS, ACTOR SUPPRESSION, & PAYLOAD CHECKS
    // =========================================================================

    public function test_task_assigned_notifies_assignee_and_actor_never_notifies_self(): void
    {
        // 1. Lead assigns to employee -> Employee notified, Lead not notified
        Notification::truncate();
        TaskAssignedEvent::dispatch($this->task, $this->employee, $this->lead);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employee->id,
            'type'    => 'task_assigned',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->lead->id,
        ]);

        // 2. Lead assigns task to themselves -> Actor self-notification suppressed
        Notification::truncate();
        TaskAssignedEvent::dispatch($this->task, $this->lead, $this->lead);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->lead->id,
        ]);
    }

    public function test_task_reassigned_notifies_both_old_and_new_assignee(): void
    {
        Notification::truncate();
        TaskAssignedEvent::dispatch($this->task, $this->employee, $this->manager, $this->lead, 'Role change');

        // New assignee gets task_assigned
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employee->id,
            'type'    => 'task_assigned',
        ]);
        // Old assignee gets task_reassigned handover alert
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->lead->id,
            'type'    => 'task_reassigned',
        ]);
        // Actor is not notified
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->manager->id,
        ]);
    }

    public function test_review_requested_notifies_team_lead_and_manager(): void
    {
        Notification::truncate();
        TaskSubmittedForReviewEvent::dispatch($this->task, $this->employee);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->lead->id,
            'type'    => 'task_review',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->manager->id,
            'type'    => 'task_review',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->employee->id,
        ]);
    }

    public function test_task_approved_and_changes_requested_recipients(): void
    {
        Notification::truncate();
        TaskChangesRequestedEvent::dispatch($this->task, $this->lead, 'Missing test coverage');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employee->id,
            'type'    => 'task_changes_requested',
        ]);

        Notification::truncate();
        TaskCompletedEvent::dispatch($this->task, $this->lead);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employee->id,
            'type'    => 'task_completed',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->lead->id,
        ]);
    }

    // =========================================================================
    // 7. INBOX ISOLATION & PAGINATION
    // =========================================================================

    public function test_inbox_isolation_user_cannot_read_or_delete_other_users_notifications(): void
    {
        $leadNotif = Notification::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->lead->id,
            'type'       => 'project_lead_assigned',
            'title'      => 'Private Lead Notice',
            'message'    => 'Confidential project lead instructions',
        ]);

        Sanctum::actingAs($this->employee);

        // Employee cannot view in their list
        $listRes = $this->getJson('/api/v1/notifications');
        $listRes->assertOk();
        $ids = collect($listRes->json('data'))->pluck('id')->all();
        $this->assertNotContains($leadNotif->id, $ids);

        // Employee cannot mark read
        $markRes = $this->putJson("/api/v1/notifications/{$leadNotif->id}/read");
        $markRes->assertStatus(404);

        // Employee cannot delete
        $delRes = $this->deleteJson("/api/v1/notifications/{$leadNotif->id}");
        $delRes->assertStatus(404);

        // Lead notification remains unread
        $this->assertNull($leadNotif->fresh()->read_at);
    }

    public function test_mark_all_read_and_unread_count_are_isolated(): void
    {
        Notification::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->lead->id,
            'type'       => 'task_assigned',
            'title'      => 'Lead Task',
            'message'    => 'Msg',
        ]);

        $empNotif = Notification::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->employee->id,
            'type'       => 'task_assigned',
            'title'      => 'Emp Task',
            'message'    => 'Msg',
        ]);

        Sanctum::actingAs($this->employee);

        $unreadRes = $this->getJson('/api/v1/notifications/unread-count');
        $unreadRes->assertOk()->assertJsonPath('data.unread_count', 1);

        $this->postJson('/api/v1/notifications/mark-all-read')->assertOk();

        // Employee's notification is read, Lead's is still unread
        $this->assertNotNull($empNotif->fresh()->read_at);
        $this->assertNull(Notification::where('user_id', $this->lead->id)->first()->read_at);
    }

    // =========================================================================
    // 8. NOTIFICATION TYPE REGISTRY DATA PROVIDER TEST
    // =========================================================================

    public function test_all_registered_notification_types_map_to_valid_categories(): void
    {
        foreach (NotificationTypeRegistry::ALL_TYPES as $type) {
            $cat = NotificationTypeRegistry::getCategoryForType($type);
            $this->assertContains($cat, ['tasks', 'projects', 'chat', 'deadlines', 'system', 'general'], "Type {$type} mapped to invalid category {$cat}");

            // Verify preference model can evaluate without exceptions
            $pref = new UserNotificationPreference([
                'task_alerts'     => true,
                'deadline_alerts' => true,
                'chat_alerts'     => true,
                'project_alerts'  => true,
            ]);
            $this->assertTrue($pref->isAlertEnabled($type));
        }
    }

    public function test_prune_notifications_command_deletes_old_read_notifications_only(): void
    {
        // 1. Old read notification (older than 90 days)
        $oldRead = Notification::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->employee->id,
            'type'       => 'task_assigned',
            'title'      => 'Old Read Task',
            'message'    => 'Old message',
            'read_at'    => now()->subDays(95),
        ]);
        $oldRead->forceFill(['created_at' => now()->subDays(95)])->saveQuietly();

        // 2. Old unread notification (should be kept)
        $oldUnread = Notification::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->employee->id,
            'type'       => 'task_assigned',
            'title'      => 'Old Unread Task',
            'message'    => 'Old unread message',
            'read_at'    => null,
        ]);
        $oldUnread->forceFill(['created_at' => now()->subDays(95)])->saveQuietly();

        // 3. Recent read notification (should be kept)
        $recentRead = Notification::create([
            'company_id' => $this->company->id,
            'user_id'    => $this->employee->id,
            'type'       => 'task_assigned',
            'title'      => 'Recent Read Task',
            'message'    => 'Recent message',
            'read_at'    => now()->subDays(5),
        ]);
        $recentRead->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('capeonn:prune-notifications', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $oldRead->id]);
        $this->assertDatabaseHas('notifications', ['id' => $oldUnread->id]);
        $this->assertDatabaseHas('notifications', ['id' => $recentRead->id]);
    }
}
