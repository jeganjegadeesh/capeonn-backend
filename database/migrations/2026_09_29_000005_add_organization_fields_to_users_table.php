<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable so any existing rows (e.g. the seeded test user) don't break the migration.
            // The API will require these for every new employee.
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
            $table->foreignId('role_id')->nullable()->after('designation_id')->constrained()->restrictOnDelete();
            // Manager -> Team Lead -> Employee chain: each user points to their direct superior.
            $table->foreignId('reports_to_id')->nullable()->after('role_id')->constrained('users')->nullOnDelete();

            $table->string('employee_code', 30)->nullable()->after('reports_to_id');
            $table->string('phone', 30)->nullable()->after('email');
            $table->date('joined_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();

            $table->unique(['company_id', 'employee_code']);
            $table->index(['company_id', 'department_id']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('head_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['head_user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['department_id']);
            $table->dropForeign(['designation_id']);
            $table->dropForeign(['role_id']);
            $table->dropForeign(['reports_to_id']);

            $table->dropUnique(['company_id', 'employee_code']);
            $table->dropIndex(['company_id', 'department_id']);

            $table->dropColumn([
                'company_id', 'department_id', 'designation_id', 'role_id', 'reports_to_id',
                'employee_code', 'phone', 'joined_on', 'is_active', 'last_login_at', 'deleted_at',
            ]);
        });
    }
};
