<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Pending / Server-verified Uploads Table
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type');
            $table->string('disk')->default('local');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['company_id']);
        });

        // 2. Chat Message Enhancements (Pins, Deletions, Mentions)
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('is_edited');
            $table->timestamp('pinned_at')->nullable()->after('is_pinned');
            $table->foreignId('pinned_by_id')->nullable()->after('pinned_at')->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by_id')->nullable()->after('pinned_by_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('chat_message_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['chat_message_id', 'user_id']);
            $table->index(['user_id']);
        });

        // 3. Conversation Enhancements (Avatar, Invite policy, Max participants)
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('avatar_url')->nullable()->after('title');
            $table->boolean('allow_member_invites')->default(true)->after('avatar_url');
            $table->unsignedInteger('max_participants')->default(100)->after('allow_member_invites');
        });

        // 4. Project File Versioning
        Schema::table('project_files', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('category');
        });

        // 5. User Presence Privacy
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('hide_presence')->default(false)->after('last_seen_at');
        });

        // 6. Device Tokens Table (Phase 8 FCM push notification readiness)
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('platform')->default('android'); // android, ios, web
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hide_presence');
        });

        Schema::table('project_files', function (Blueprint $table) {
            $table->dropColumn('version');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['avatar_url', 'allow_member_invites', 'max_participants']);
        });

        Schema::dropIfExists('chat_message_mentions');

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['pinned_by_id']);
            $table->dropForeign(['deleted_by_id']);
            $table->dropColumn(['is_pinned', 'pinned_at', 'pinned_by_id', 'deleted_by_id']);
        });

        Schema::dropIfExists('uploads');
    }
};
