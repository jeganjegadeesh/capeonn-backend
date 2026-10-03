<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('department_id')->constrained('users')->nullOnDelete();
            $table->foreignId('status_changed_by_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable()->after('status_changed_by_id');
            $table->text('status_change_reason')->nullable()->after('status_changed_at');
            $table->timestamp('completion_requested_at')->nullable()->after('status_change_reason');
            $table->foreignId('completion_requested_by_id')->nullable()->after('completion_requested_at')->constrained('users')->nullOnDelete();
            $table->text('completion_request_notes')->nullable()->after('completion_requested_by_id');
        });

        // Migrate status data: planning -> planned, in_progress -> active
        DB::table('projects')->where('status', 'planning')->update(['status' => 'planned']);
        DB::table('projects')->where('status', 'in_progress')->update(['status' => 'active']);

        // Update default manager_id from department head or created_by
        $projects = DB::table('projects')->get();
        foreach ($projects as $p) {
            $deptHead = null;
            if ($p->department_id) {
                $deptHead = DB::table('departments')->where('id', $p->department_id)->value('head_user_id');
            }
            DB::table('projects')->where('id', $p->id)->update([
                'manager_id' => $deptHead ?? $p->created_by_id,
            ]);
        }

        // Add audit log columns to project_activities
        Schema::table('project_activities', function (Blueprint $table) {
            $table->string('field')->nullable()->after('action');
            $table->text('old_value')->nullable()->after('field');
            $table->text('new_value')->nullable()->after('old_value');
            $table->text('reason')->nullable()->after('new_value');
        });
    }

    public function down(): void
    {
        Schema::table('project_activities', function (Blueprint $table) {
            $table->dropColumn(['field', 'old_value', 'new_value', 'reason']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropForeign(['status_changed_by_id']);
            $table->dropForeign(['completion_requested_by_id']);
            $table->dropColumn([
                'manager_id',
                'status_changed_by_id',
                'status_changed_at',
                'status_change_reason',
                'completion_requested_at',
                'completion_requested_by_id',
                'completion_request_notes',
            ]);
        });
    }
};
