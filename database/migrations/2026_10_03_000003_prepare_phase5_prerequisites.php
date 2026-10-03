<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Remove stored progress column from projects table (Phase 5 computes it dynamically from tasks)
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'progress')) {
                $table->dropColumn('progress');
            }
        });

        // 2. Create tasks table reserving project_id foreign key for tasks
        if (! Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('status')->default('backlog'); // backlog, assigned, in_progress, review, changes_required, completed
                $table->string('priority')->default('medium'); // low, medium, high, urgent
                $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('due_date')->nullable();
                $table->decimal('estimated_hours', 8, 2)->nullable();
                $table->decimal('actual_hours', 8, 2)->nullable()->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['project_id', 'status']);
                $table->index(['company_id', 'assigned_to_id']);
            });
        }

        // 3. Add task_id column to project_activities for Phase 5 task audit logging
        Schema::table('project_activities', function (Blueprint $table) {
            if (! Schema::hasColumn('project_activities', 'task_id')) {
                $table->foreignId('task_id')->nullable()->after('project_id')->constrained('tasks')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_activities', function (Blueprint $table) {
            if (Schema::hasColumn('project_activities', 'task_id')) {
                $table->dropForeign(['task_id']);
                $table->dropColumn('task_id');
            }
        });

        Schema::dropIfExists('tasks');

        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'progress')) {
                $table->unsignedTinyInteger('progress')->nullable()->default(null);
            }
        });
    }
};
