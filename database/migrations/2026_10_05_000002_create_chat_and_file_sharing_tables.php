<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Conversations (Direct, Group, Project)
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('type')->default('direct'); // direct, group, project
            $table->string('title')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'type']);
            $table->index(['project_id']);
        });

        // 2. Participants
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('member'); // admin, member
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->boolean('is_muted')->default(false);
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'conversation_id']);
        });

        // 3. Chat Messages
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->string('type')->default('text'); // text, file, system
            $table->unsignedBigInteger('reply_to_id')->nullable()->index();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->boolean('is_edited')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'created_at']);
        });

        // 4. Chat Message Attachments
        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type');
            $table->timestamps();

            $table->index(['chat_message_id']);
        });

        // 5. Project & Task Workspace Files
        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('uploaded_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type');
            $table->string('category')->default('general'); // specification, design, document, report, archive, general
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'project_id']);
            $table->index(['project_id', 'created_at']);
            $table->index(['task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_files');
        Schema::dropIfExists('chat_attachments');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
