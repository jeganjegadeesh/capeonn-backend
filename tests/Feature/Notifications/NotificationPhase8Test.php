<?php

namespace Tests\Feature\Notifications;

use App\Events\Notifications\NotificationBroadcastEvent;
use App\Events\Projects\ProjectOverdueEvent;
use App\Events\Tasks\TaskAssignedEvent;
use App\Events\Tasks\TaskOverdueEvent;
use App\Models\Company;
use App\Models\Department;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\FcmNotificationService;
use App\Services\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationPhase8Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $department;
    private User $lead;
    private User $employee;
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

        $tlRole  = Role::where('slug', Role::TEAM_LEAD)->firstOrFail();
        $empRole = Role::where('slug', Role::EMPLOYEE)->firstOrFail();

        $this->lead = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id'    => $tlRole->id,
            'name'       => 'Lead User',
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

    public function test_user_can_register_device_token_for_all_platforms(): void
    {
        Sanctum::actingAs($this->employee);

        $platforms = ['android', 'ios', 'web', 'windows'];

        foreach ($platforms as $idx => $platform) {
            $token = "token_sample_{$platform}_{$idx}";
            $response = $this->postJson('/api/v1/device-tokens', [
                'token'       => $token,
                'platform'    => $platform,
                'device_name' => ucfirst($platform) . " Device",
            ]);

            $response->assertStatus(201)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.platform', $platform)
                ->assertJsonPath('data.token', $token);

            $this->assertDatabaseHas('device_tokens', [
                'user_id'     => $this->employee->id,
                'token'       => $token,
                'platform'    => $platform,
                'device_name' => ucfirst($platform) . " Device",
            ]);
        }
    }

    public function test_user_can_list_and_unregister_device_tokens(): void
    {
        Sanctum::actingAs($this->employee);

        DeviceToken::create([
            'user_id'     => $this->employee->id,
            'token'       => 'fcm_token_to_remove',
            'platform'    => 'windows',
            'device_name' => 'Work Desktop',
        ]);

        // List devices
        $listRes = $this->getJson('/api/v1/device-tokens');
        $listRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        // Unregister
        $delRes = $this->deleteJson('/api/v1/device-tokens', [
            'token' => 'fcm_token_to_remove',
        ]);
        $delRes->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('device_tokens', [
            'token' => 'fcm_token_to_remove',
        ]);
    }

    public function test_user_can_get_and_update_notification_preferences(): void
    {
        Sanctum::actingAs($this->employee);

        // Default preferences
        $getRes = $this->getJson('/api/v1/notifications/preferences');
        $getRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.push_enabled', true)
            ->assertJsonPath('data.task_alerts', true);

        // Update preferences: turn off chat_alerts and push_enabled
        $updateRes = $this->putJson('/api/v1/notifications/preferences', [
            'push_enabled' => false,
            'chat_alerts'  => false,
        ]);

        $updateRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.push_enabled', false)
            ->assertJsonPath('data.chat_alerts', false)
            ->assertJsonPath('data.task_alerts', true);

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id'      => $this->employee->id,
            'push_enabled' => false,
            'chat_alerts'  => false,
        ]);
    }

    public function test_notification_service_respects_user_preferences_opt_out(): void
    {
        // Disable task_alerts for employee
        UserNotificationPreference::updateOrCreate(
            ['user_id' => $this->employee->id],
            ['task_alerts' => false]
        );

        $service = app(NotificationService::class);
        $result = $service->notifyUser(
            $this->employee->id,
            'task_assigned',
            'New Task',
            'You were assigned a task',
            ['task_id' => $this->task->id]
        );

        $this->assertNull($result);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->employee->id,
            'type'    => 'task_assigned',
        ]);
    }

    public function test_notification_service_broadcasts_websocket_event(): void
    {
        Event::fake([NotificationBroadcastEvent::class]);

        $service = app(NotificationService::class);
        $notification = $service->notifyUser(
            $this->employee->id,
            'task_assigned',
            'Task Assigned',
            'You were assigned a task',
            ['task_id' => $this->task->id]
        );

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notifications', [
            'id'      => $notification->id,
            'user_id' => $this->employee->id,
        ]);

        Event::assertDispatched(NotificationBroadcastEvent::class, function ($event) use ($notification) {
            return $event->notification->id === $notification->id
                && $event->unreadCount === 1;
        });
    }

    public function test_fcm_notification_service_prunes_invalid_tokens(): void
    {
        $token = 'invalid_device_token_xyz';
        DeviceToken::create([
            'user_id'  => $this->employee->id,
            'token'    => $token,
            'platform' => 'android',
        ]);

        $this->assertDatabaseHas('device_tokens', ['token' => $token]);

        $fcmService = app(FcmNotificationService::class);
        $fcmService->pruneToken($token);

        $this->assertDatabaseMissing('device_tokens', ['token' => $token]);
    }

    public function test_deadline_and_overdue_notifications_are_deduplicated_same_day(): void
    {
        // Trigger TaskOverdueEvent twice
        TaskOverdueEvent::dispatch($this->task, 2);
        TaskOverdueEvent::dispatch($this->task, 2);

        // Only 1 notification should exist for the assignee
        $count = Notification::where('user_id', $this->employee->id)
            ->where('type', 'task_overdue')
            ->count();

        $this->assertEquals(1, $count);

        // Trigger ProjectOverdueEvent twice
        ProjectOverdueEvent::dispatch($this->project, 3);
        ProjectOverdueEvent::dispatch($this->project, 3);

        $projCount = Notification::where('user_id', $this->lead->id)
            ->where('type', 'project_overdue')
            ->count();

        $this->assertEquals(1, $projCount);
    }

    public function test_test_push_endpoint_returns_success_when_token_registered(): void
    {
        Sanctum::actingAs($this->employee);

        // When no device token registered
        $noTokenRes = $this->postJson('/api/v1/notifications/test-push');
        $noTokenRes->assertStatus(422)
            ->assertJsonPath('success', false);

        // Register token
        DeviceToken::create([
            'user_id'  => $this->employee->id,
            'token'    => 'valid_test_push_token_123',
            'platform' => 'web',
        ]);

        $withTokenRes = $this->postJson('/api/v1/notifications/test-push');
        $withTokenRes->assertOk()
            ->assertJsonPath('success', true);
    }
}
