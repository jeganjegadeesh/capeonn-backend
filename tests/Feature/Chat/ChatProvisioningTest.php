<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use App\Services\ChatProvisioningService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme Technologies',
            'code' => 'ACM',
            'is_active' => true,
        ]);
    }

    public function test_new_employee_is_automatically_connected_to_all_colleagues_with_welcome_message(): void
    {
        $empRole = Role::where('slug', 'employee')->first();

        // Create 2 existing active employees in this company
        $emp1 = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id' => $empRole->id,
            'name' => 'Alice Worker',
            'is_active' => true,
        ]);

        $emp2 = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id' => $empRole->id,
            'name' => 'Bob Engineer',
            'is_active' => true,
        ]);

        // Create a new employee
        $newEmp = User::factory()->create([
            'company_id' => $this->company->id,
            'role_id' => $empRole->id,
            'name' => 'Charlie Newbie',
            'is_active' => true,
        ]);

        $service = app(ChatProvisioningService::class);
        $count = $service->connectNewEmployeeToAll($newEmp);

        $this->assertEquals(2, $count);

        // Verify direct conversation between Charlie and Alice
        $aliceConv = Conversation::where('company_id', $this->company->id)
            ->where('type', Conversation::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $newEmp->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $emp1->id))
            ->first();

        $this->assertNotNull($aliceConv);

        // Verify in-built initial message
        $msg = ChatMessage::where('conversation_id', $aliceConv->id)->first();
        $this->assertNotNull($msg);
        $this->assertEquals($newEmp->id, $msg->user_id);
        $this->assertStringContainsString('Charlie Newbie', $msg->message);
    }
}
