<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_attendance_applicable')->default(true)->after('is_active');
            $table->decimal('salary', 12, 2)->nullable()->after('employment_type');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('approver_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->boolean('final_approver')->default(false)->after('approver_id');
        });

        Schema::table('employee_documents', function (Blueprint $table) {
            $table->boolean('is_verified')->default(false)->after('mime_type');
            $table->foreignId('verified_by_id')->nullable()->after('is_verified')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropForeign(['verified_by_id']);
            $table->dropColumn(['is_verified', 'verified_by_id', 'verified_at']);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['approver_id']);
            $table->dropColumn(['approver_id', 'final_approver']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_attendance_applicable', 'salary']);
        });
    }
};
