<?php

namespace Tests\Feature\Access;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->company = Company::create(['name' => 'Test Co', 'code' => 'TST']);

        // Throw-away routes that exist only for these tests.
        $guard = ['auth:sanctum', 'active'];
        Route::middleware([...$guard, 'permission:employees.manage'])
            ->get('/api/_test/manage-employees', fn () => response()->json(['ok' => true]));
        Route::middleware([...$guard, 'permission:leave.approve,tasks.manage'])
            ->get('/api/_test/any-of', fn () => response()->json(['ok' => true]));
        Route::middleware([...$guard, 'permission:does.not.exist'])
            ->get('/api/_test/unknown', fn () => response()->json(['ok' => true]));
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create([
            'company_id' => $this->company->id,
            'role_id'    => Role::where('slug', $role)->value('id'),
            'is_active'  => true,
        ]);
    }

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/_test/manage-employees')->assertStatus(401);
    }

    public function test_role_with_permission_is_allowed(): void
    {
        Sanctum::actingAs($this->makeUser(Role::MANAGER));

        $this->getJson('/api/_test/manage-employees')->assertOk();
    }

    public function test_role_without_permission_gets_403_json(): void
    {
        Sanctum::actingAs($this->makeUser(Role::EMPLOYEE));

        $this->getJson('/api/_test/manage-employees')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You do not have permission to perform this action.');
    }

    public function test_team_lead_cannot_manage_employees_but_can_use_tasks(): void
    {
        Sanctum::actingAs($this->makeUser(Role::TEAM_LEAD));

        $this->getJson('/api/_test/manage-employees')->assertStatus(403);
        $this->getJson('/api/_test/any-of')->assertOk(); // has tasks.manage
    }

    public function test_any_of_permissions_denies_when_none_match(): void
    {
        Sanctum::actingAs($this->makeUser(Role::EMPLOYEE));

        $this->getJson('/api/_test/any-of')->assertStatus(403);
    }

    public function test_unknown_permission_is_denied_even_for_admin(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMIN));

        $this->getJson('/api/_test/unknown')->assertStatus(403);
    }

    public function test_user_without_a_role_is_denied(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'role_id' => null, 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/_test/manage-employees')->assertStatus(403);
    }
}
