<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Creates the company and its first Admin user (values come from config/capeonn.php). */
class CompanyAdminSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('capeonn.admin.password');

        if (! $password) {
            if (! app()->environment('local')) {
                throw new RuntimeException('Set CAPEONN_ADMIN_PASSWORD in .env before seeding a non-local environment.');
            }
            $password = 'Password@123'; // local development only
        }

        $company = Company::firstOrCreate(
            ['code' => config('capeonn.company.code')],
            ['name' => config('capeonn.company.name')],
        );

        $adminRole = Role::where('slug', Role::ADMIN)->firstOrFail();

        User::firstOrCreate(
            ['email' => config('capeonn.admin.email')],
            [
                'name'          => config('capeonn.admin.name'),
                'password'      => $password,
                'company_id'    => $company->id,
                'role_id'       => $adminRole->id,
                'employee_code' => $company->code.'-0001',
                'joined_on'     => now()->toDateString(),
                'is_active'     => true,
            ],
        );
    }
}
