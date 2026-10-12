<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add quiet hours and privacy settings to user_notification_preferences
        Schema::table('user_notification_preferences', function (Blueprint $table) {
            if (! Schema::hasColumn('user_notification_preferences', 'quiet_hours_enabled')) {
                $table->boolean('quiet_hours_enabled')->default(false)->after('project_alerts');
            }
            if (! Schema::hasColumn('user_notification_preferences', 'quiet_hours_start')) {
                $table->string('quiet_hours_start', 10)->nullable()->after('quiet_hours_enabled');
            }
            if (! Schema::hasColumn('user_notification_preferences', 'quiet_hours_end')) {
                $table->string('quiet_hours_end', 10)->nullable()->after('quiet_hours_start');
            }
            if (! Schema::hasColumn('user_notification_preferences', 'hide_previews')) {
                $table->boolean('hide_previews')->default(false)->after('quiet_hours_end');
            }
            if (! Schema::hasColumn('user_notification_preferences', 'chat_mentions_only')) {
                $table->boolean('chat_mentions_only')->default(false)->after('hide_previews');
            }
        });

        // 2. Add composite index on notifications (user_id, read_at) for fast unread queries
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'read_at']);
        });

        // 3. Create notification_deliveries table for delivery audit log
        if (! Schema::hasTable('notification_deliveries')) {
            Schema::create('notification_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('notification_id')->nullable()->constrained('notifications')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('channel', 30); // fcm, websocket, email
                $table->string('status', 30); // sent, failed, skipped
                $table->text('error')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
                $table->index(['status', 'channel']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'read_at']);
        });

        Schema::table('user_notification_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'quiet_hours_enabled',
                'quiet_hours_start',
                'quiet_hours_end',
                'hide_previews',
                'chat_mentions_only',
            ]);
        });
    }
};
