<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add auto-stop and pause columns to time_entries
        Schema::table('time_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('time_entries', 'is_auto_stopped')) {
                $table->boolean('is_auto_stopped')->default(false)->after('is_manual');
            }
            if (! Schema::hasColumn('time_entries', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('is_auto_stopped');
            }
        });

        // 2. Add position column to tasks for custom task ordering
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'position')) {
                $table->unsignedInteger('position')->default(0)->after('priority');
            }
        });

        // 3. Create notifications table for project & task events
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type'); // project_overdue, task_assigned, task_review, etc.
                $table->string('title');
                $table->text('message');
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'user_id', 'read_at']);
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'position')) {
                $table->dropColumn('position');
            }
        });

        Schema::table('time_entries', function (Blueprint $table) {
            if (Schema::hasColumn('time_entries', 'paused_at')) {
                $table->dropColumn('paused_at');
            }
            if (Schema::hasColumn('time_entries', 'is_auto_stopped')) {
                $table->dropColumn('is_auto_stopped');
            }
        });
    }
};
