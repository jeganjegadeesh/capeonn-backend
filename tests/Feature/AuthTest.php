<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password@123';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->company = Company::create(['name' => 'Test Co', 'code' => 'TST']);
    }

    private function makeUser(string $role = Role::EMPLOYEE, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'role_id'    => Role::where('slug', $role)->value('id'),
            'password'   => self::PASSWORD,
            'is_active'  => true,
        ], $overrides));
    }

    private function login(string $email, string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => $password, 'device_name' => 'phpunit',
        ]);
    }

    /** Sanctum caches the resolved user per guard; clear it to simulate a fresh request. */
    private function newRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_login_returns_token_user_and_permissions(): void
    {
        $user = $this->makeUser(Role::MANAGER);

        $response = $this->login($user->email)->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.role.slug', 'manager')
            ->assertJsonPath('data.user.company.code', 'TST');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame('department', $response->json('data.user.permissions')['employees.manage']);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $user = $this->makeUser();

        $this->login($user->email, 'wrong-password')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_login_rejects_unknown_email(): void
    {
        $this->login('nobody@example.com')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_login_blocks_deactivated_user(): void
    {
        $user = $this->makeUser(Role::EMPLOYEE, ['is_active' => false]);

        $this->login($user->email)->assertStatus(403)->assertJsonPath('success', false);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_me_returns_current_user(): void
    {
        $user  = $this->makeUser(Role::TEAM_LEAD);
        $token = $this->login($user->email)->json('data.token');
        $this->newRequest();

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role.slug', 'team_lead');
    }

    public function test_logout_revokes_the_token(): void
    {
        $user  = $this->makeUser();
        $token = $this->login($user->email)->json('data.token');
        $this->newRequest();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->newRequest();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_deactivated_user_loses_access_with_existing_token(): void
    {
        $user  = $this->makeUser();
        $token = $this->login($user->email)->json('data.token');

        $user->update(['is_active' => false]);
        $this->newRequest();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(403);
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user  = $this->makeUser();
        $token = $this->login($user->email)->json('data.token');
        $this->newRequest();

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password'      => 'not-my-password',
            'password'              => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_change_password_enforces_password_policy(): void
    {
        $user  = $this->makeUser();
        $token = $this->login($user->email)->json('data.token');
        $this->newRequest();

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password'      => self::PASSWORD,
            'password'              => 'weak',
            'password_confirmation' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_change_password_signs_out_other_devices(): void
    {
        $user   = $this->makeUser();
        $token1 = $this->login($user->email)->json('data.token');
        $this->login($user->email); // second device
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->newRequest();

        $this->withToken($token1)->postJson('/api/v1/auth/change-password', [
            'current_password'      => self::PASSWORD,
            'password'              => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->login($user->email, 'NewPassword1')->assertOk();
        $this->login($user->email, self::PASSWORD)->assertStatus(422);
    }
}
