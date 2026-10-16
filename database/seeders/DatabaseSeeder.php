<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database for launch.
     *
     * Runs only the essential seeds necessary to launch the application:
     * - Roles and Permissions (RolePermissionSeeder)
     * - Company & Initial Super Admin User (CompanyAdminSeeder)
     * - Essential Organizational Defaults: Leave Types, Primary Office Location, Public Holidays (EssentialDataSeeder)
     *
     * Demo data (dummy employees, projects, tasks, chats, attendance logs) is excluded.
     * To seed demo data explicitly for local testing, run:
     * php artisan db:seed --class=DemoOrganizationSeeder
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            CompanyAdminSeeder::class,
            EssentialDataSeeder::class,
        ]);
    }
}
