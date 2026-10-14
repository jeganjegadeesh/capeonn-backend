<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityPhase11Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Role $superAdminRole;
    private Role $employeeRole;
    private User $superAdmin;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Capeonn Security Test Corp',
            'code' => 'CSTC',
            'email' => 'security@capeonn.test',
            'is_active' => true,
        ]);

        $this->superAdminRole = Role::where('slug', 'super_admin')->first();
        $this->employeeRole = Role::where('slug', 'employee')->first();

        $dept = Department::create([
            'company_id' => $this->company->id,
            'name' => 'Cybersecurity',
            'code' => 'CYBER',
        ]);

        $desig = Designation::create([
            'company_id' => $this->company->id,
            'name' => 'Security Analyst',
        ]);

        $this->superAdmin = User::create([
            'company_id' => $this->company->id,
            'role_id' => $this->superAdminRole->id,
            'department_id' => $dept->id,
            'designation_id' => $desig->id,
            'name' => 'Super Administrator',
            'email' => 'admin.sec@capeonn.test',
            'password' => Hash::make('AdminSec#123'),
            'is_active' => true,
        ]);

        $this->employee = User::create([
            'company_id' => $this->company->id,
            'role_id' => $this->employeeRole->id,
            'department_id' => $dept->id,
            'designation_id' => $desig->id,
            'name' => 'Staff Member',
            'email' => 'staff.sec@capeonn.test',
            'password' => Hash::make('StaffSec#123'),
            'is_active' => true,
        ]);
    }

    /**
     * 1. OWASP Security Headers appended to responses.
     */
    public function test_security_headers_present_on_api_responses(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $response->assertStatus(200);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * 2. Unauthenticated requests strictly receive 401.
     */
    public function test_unauthenticated_request_to_protected_endpoint_returns_401(): void
    {
        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Unauthenticated.',
        ]);
    }

    /**
     * 3. Brute force login attempts are rate limited (5/min).
     */
    public function test_login_rate_limiting_enforced(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'staff.sec@capeonn.test',
                'password' => 'WrongPassword#1',
            ]);
        }

        // 6th attempt must be throttled with HTTP 429
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff.sec@capeonn.test',
            'password' => 'WrongPassword#1',
        ]);

        $response->assertStatus(429);
    }

    /**
     * 4. Successful login writes an audit log.
     */
    public function test_successful_login_generates_audit_log(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.sec@capeonn.test',
            'password' => 'AdminSec#123',
            'device_name' => 'desktop-windows',
        ])->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->company->id,
            'user_id' => $this->superAdmin->id,
            'event' => 'auth.login',
            'severity' => 'info',
        ]);
    }

    /**
     * 5. Failed login writes a security warning audit log.
     */
    public function test_failed_login_generates_security_audit_log(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.sec@capeonn.test',
            'password' => 'IncorrectPassword#999',
        ])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->company->id,
            'user_id' => $this->superAdmin->id,
            'event' => 'auth.failed_login',
            'severity' => 'warning',
        ]);
    }

    private function newRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * 6. Password change terminates prior sessions and logs security event.
     */
    public function test_password_change_revokes_prior_tokens(): void
    {
        $token1 = $this->employee->createToken('phone')->plainTextToken;
        $token2 = $this->employee->createToken('laptop')->plainTextToken;

        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->newRequest();

        // Perform password change using token 2
        $response = $this->withHeader('Authorization', "Bearer $token2")
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'StaffSec#123',
                'password' => 'NewSecPassword#456',
                'password_confirmation' => 'NewSecPassword#456',
            ]);

        $response->assertStatus(200);

        // One token remains (token 2); token 1 was deleted
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->newRequest();
        $this->withHeader('Authorization', "Bearer $token1")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->newRequest();
        $this->withHeader('Authorization', "Bearer $token2")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->company->id,
            'user_id' => $this->employee->id,
            'event' => 'auth.password_changed',
        ]);
    }

    /**
     * 7. Non-Super Admin cannot view audit logs (403 Forbidden).
     */
    public function test_non_super_admin_cannot_access_system_audit_logs(): void
    {
        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/admin/audit-logs');

        $response->assertStatus(403);
    }

    /**
     * 8. Super Admin can view and filter audit logs.
     */
    public function test_super_admin_can_view_and_filter_audit_logs(): void
    {
        AuditLog::create([
            'company_id' => $this->company->id,
            'user_id' => $this->superAdmin->id,
            'event' => 'security.alert',
            'description' => 'Suspicious token activity detected',
            'severity' => 'critical',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/audit-logs?severity=critical');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.severity', 'critical');
        $response->assertJsonPath('data.0.event', 'security.alert');
    }

    /**
     * 9. Audit logs CSV export streams clean CSV with BOM.
     */
    public function test_audit_logs_csv_export_streams_clean_csv(): void
    {
        AuditLog::create([
            'company_id' => $this->company->id,
            'user_id' => $this->superAdmin->id,
            'event' => 'auth.login',
            'description' => 'User logged in',
            'severity' => 'info',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->get('/api/v1/admin/audit-logs/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringContainsString('Timestamp (UTC)', $content);
        $this->assertStringContainsString('Event Tag', $content);
        $this->assertStringContainsString('auth.login', $content);
    }

    /**
     * 10. Multi-tenant boundary isolation prevents cross-company leakage.
     */
    public function test_cross_tenant_isolation_prevents_data_leakage(): void
    {
        $otherCompany = Company::create([
            'name' => 'Foreign Corp Ltd',
            'code' => 'FCL',
            'email' => 'foreign@other.test',
            'is_active' => true,
        ]);

        $foreignProject = Project::create([
            'company_id' => $otherCompany->id,
            'name' => 'Confidential Foreign Project',
            'status' => 'active',
            'priority' => 'high',
            'start_date' => now()->toDateString(),
            'deadline' => now()->addMonth()->toDateString(),
        ]);

        // User from Company A must receive 404 when querying Company B's project
        $this->actingAs($this->superAdmin)
            ->getJson("/api/v1/projects/{$foreignProject->id}")
            ->assertStatus(404);
    }

    /**
     * 11. Malicious executable script file uploads are rejected.
     */
    public function test_malicious_executable_file_upload_is_blocked(): void
    {
        $fakePhpScript = UploadedFile::fake()->create('malicious.php', 100, 'text/x-php');

        $response = $this->actingAs($this->employee)
            ->postJson('/api/v1/uploads', [
                'file' => $fakePhpScript,
            ]);

        $response->assertStatus(422);
    }
}
