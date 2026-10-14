<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['company_id', 'status', 'due_date'], 'tasks_analytics_status_due_idx');
            $table->index(['assigned_to_id', 'status'], 'tasks_analytics_assigned_status_idx');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->index(['task_id', 'started_at'], 'time_entries_task_started_idx');
            $table->index(['user_id', 'started_at'], 'time_entries_user_started_idx');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->index(['department_id', 'status'], 'projects_dept_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_analytics_status_due_idx');
            $table->dropIndex('tasks_analytics_assigned_status_idx');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex('time_entries_task_started_idx');
            $table->dropIndex('time_entries_user_started_idx');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_dept_status_idx');
        });
    }
};
