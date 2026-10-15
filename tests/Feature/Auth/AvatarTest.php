<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $company = Company::create(['name' => 'Test Co', 'code' => 'TST']);

        $this->user = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('slug', Role::EMPLOYEE)->value('id'),
            'is_active' => true,
        ]);
    }

    public function test_user_can_upload_avatar(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('profile.jpg', 200, 200);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/auth/avatar', [
                'avatar' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'avatar_url',
                    'avatar_path',
                ],
            ]);

        $this->user->refresh();
        $this->assertNotNull($this->user->avatar_path);
        $this->assertNotNull($this->user->avatar_url);
        Storage::disk('public')->assertExists($this->user->avatar_path);
    }

    public function test_user_can_delete_avatar(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('profile.png');
        $path = $file->storeAs('avatars', 'avatar_test.png', 'public');
        $this->user->update(['avatar_path' => $path]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/auth/avatar');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.avatar_url', null);

        $this->user->refresh();
        $this->assertNull($this->user->avatar_path);
        $this->assertNull($this->user->avatar_url);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_avatar_requires_valid_image(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/auth/avatar', [
                'avatar' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_public_avatar_endpoint_streams_file(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('pic.jpg');
        $path = $file->storeAs('avatars', 'pic.jpg', 'public');
        $this->user->update(['avatar_path' => $path]);

        $response = $this->get('/api/v1/users/' . $this->user->id . '/avatar');

        $response->assertOk();
    }
}
