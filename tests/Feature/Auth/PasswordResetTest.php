<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
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

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'role_id'    => Role::where('slug', Role::EMPLOYEE)->value('id'),
            'password'   => self::PASSWORD,
            'is_active'  => true,
        ], $overrides));
    }

    /** Requests a reset for $user and returns the plain token from the (faked) email. */
    private function requestToken(User $user): string
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        return $token;
    }

    public function test_forgot_password_sends_email_to_active_user(): void
    {
        $user = $this->makeUser();

        $this->assertNotEmpty($this->requestToken($user));
    }

    public function test_forgot_password_does_not_reveal_unknown_emails(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_sends_nothing_to_deactivated_user(): void
    {
        Notification::fake();
        $user = $this->makeUser(['is_active' => false]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_forgot_password_validates_email_format(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_reset_password_with_valid_token(): void
    {
        $user = $this->makeUser();
        $user->createToken('old-device'); // must be revoked by the reset
        $token = $this->requestToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email'                 => $user->email,
            'token'                 => $token,
            'password'              => 'BrandNew1',
            'password_confirmation' => 'BrandNew1',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'BrandNew1'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(422);
    }

    public function test_reset_token_cannot_be_used_twice(): void
    {
        $user  = $this->makeUser();
        $token = $this->requestToken($user);

        $payload = [
            'email'                 => $user->email,
            'token'                 => $token,
            'password'              => 'BrandNew1',
            'password_confirmation' => 'BrandNew1',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/reset-password', [
            'email'                 => $user->email,
            'token'                 => 'not-a-real-token',
            'password'              => 'BrandNew1',
            'password_confirmation' => 'BrandNew1',
        ])->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_reset_password_enforces_password_policy(): void
    {
        $user  = $this->makeUser();
        $token = $this->requestToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email'                 => $user->email,
            'token'                 => $token,
            'password'              => 'weak',
            'password_confirmation' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
