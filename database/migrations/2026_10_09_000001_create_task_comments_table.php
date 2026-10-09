<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_comments')) {
            Schema::create('task_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('task_comments')->nullOnDelete();
                $table->text('comment');
                $table->json('attachments')->nullable();
                $table->boolean('is_edited')->default(false);
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['task_id', 'created_at']);
                $table->index(['company_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('task_comment_mentions')) {
            Schema::create('task_comment_mentions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_comment_id')->constrained('task_comments')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['task_comment_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comment_mentions');
        Schema::dropIfExists('task_comments');
    }
};
