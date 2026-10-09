<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add submitter, submission timestamp, and explicit reviewer to tasks
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('submitted_by_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by_id');
            $table->foreignId('reviewer_id')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
        });

        // 2. Task Review rounds & audit records
        Schema::create('task_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedInteger('round_number')->default(1);
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('outcome')->nullable(); // 'approved', 'changes_requested'
            $table->text('feedback')->nullable();
            $table->boolean('checklist_passed')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'task_id']);
            $table->index(['task_id', 'round_number']);
        });

        // 3. Task Comment Edit History (revisions)
        Schema::create('task_comment_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('task_comment_id')->constrained('task_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('old_comment');
            $table->text('new_comment');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task_comment_id', 'created_at']);
        });

        // 4. Server-verified Task Comment Attachments
        Schema::create('task_comment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('task_comment_id')->constrained('task_comments')->cascadeOnDelete();
            $table->foreignId('upload_id')->nullable()->constrained('uploads')->nullOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type');
            $table->string('disk')->default('local');
            $table->timestamps();

            $table->index(['task_comment_id']);
            $table->index(['company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comment_attachments');
        Schema::dropIfExists('task_comment_edits');
        Schema::dropIfExists('task_reviews');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewer_id');
            $table->dropColumn('submitted_at');
            $table->dropConstrainedForeignId('submitted_by_id');
        });
    }
};
