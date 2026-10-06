<?php

namespace Tests\Feature\Chat;

use App\Events\Chat\UserPresenceChangedEvent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PresenceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $alice;
    private User $bob;
    private User $charlie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Capeonn Inc', 'code' => 'CAP']);
        $department = Department::create(['company_id' => $this->company->id, 'name' => 'Engineering', 'code' => 'ENG']);

        $role = Role::where('slug', Role::EMPLOYEE)->firstOrFail();

        $this->alice = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'name' => 'Alice Engineer',
        ]);

        $this->bob = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'name' => 'Bob Engineer',
        ]);

        $this->charlie = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'name' => 'Charlie Engineer',
        ]);
    }

    public function test_heartbeat_updates_last_seen_and_broadcasts_presence(): void
    {
        Event::fake([UserPresenceChangedEvent::class]);

        Sanctum::actingAs($this->alice);

        $response = $this->postJson('/api/v1/presence/heartbeat');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $this->alice->id)
            ->assertJsonPath('data.is_online', true);

        $this->alice->refresh();
        $this->assertNotNull($this->alice->last_seen_at);
        $this->assertTrue($this->alice->isOnline());

        Event::assertDispatched(UserPresenceChangedEvent::class, function ($event) {
            return (int) $event->user->id === (int) $this->alice->id && $event->isOnline === true;
        });
    }

    public function test_offline_endpoint_sets_user_to_offline(): void
    {
        Event::fake([UserPresenceChangedEvent::class]);

        Sanctum::actingAs($this->bob);

        // Initially online
        $this->bob->update(['last_seen_at' => now()]);
        $this->assertTrue($this->bob->isOnline());

        $response = $this->postJson('/api/v1/presence/offline');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $this->bob->id)
            ->assertJsonPath('data.is_online', false);

        $this->bob->refresh();
        $this->assertFalse($this->bob->isOnline());

        Event::assertDispatched(UserPresenceChangedEvent::class, function ($event) {
            return (int) $event->user->id === (int) $this->bob->id && $event->isOnline === false;
        });
    }

    public function test_presence_index_query(): void
    {
        $this->alice->update(['last_seen_at' => now()]);
        $this->bob->update(['last_seen_at' => now()->subMinutes(10)]);

        Sanctum::actingAs($this->charlie);

        $res = $this->getJson("/api/v1/presence?ids={$this->alice->id},{$this->bob->id}");
        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $data = collect($res->json('data'));
        $aliceData = $data->firstWhere('user_id', $this->alice->id);
        $bobData = $data->firstWhere('user_id', $this->bob->id);

        $this->assertTrue($aliceData['is_online']);
        $this->assertFalse($bobData['is_online']);
    }

    public function test_direct_conversation_includes_partner_presence(): void
    {
        $this->bob->update(['last_seen_at' => now()]);

        Sanctum::actingAs($this->alice);

        $convRes = $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->bob->id]);
        $convRes->assertStatus(201)
            ->assertJsonPath('data.partner.id', $this->bob->id)
            ->assertJsonPath('data.partner.is_online', true);
    }

    public function test_company_broadcast_channel_authorization(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-id',
        ]);
        require base_path('routes/channels.php');

        Sanctum::actingAs($this->alice);

        // Alice in company -> auth succeeds
        $authRes = $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => "private-company.{$this->company->id}",
            'socket_id' => '1234.5678',
        ]);
        $authRes->assertStatus(200)
            ->assertJsonPath('auth', fn ($auth) => str_starts_with($auth, 'test-key:'));

        // Other company channel -> 403
        $forbiddenRes = $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-company.9999',
            'socket_id' => '1234.5678',
        ]);
        $forbiddenRes->assertStatus(403);
    }
}
