<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('push_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('task_alerts')->default(true);
            $table->boolean('deadline_alerts')->default(true);
            $table->boolean('chat_alerts')->default(true);
            $table->boolean('project_alerts')->default(true);
            $table->timestamps();

            $table->unique('user_id');
        });

        // Add device_name to device_tokens if not present
        if (Schema::hasTable('device_tokens') && ! Schema::hasColumn('device_tokens', 'device_name')) {
            Schema::table('device_tokens', function (Blueprint $table) {
                $table->string('device_name')->nullable()->after('platform');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('device_tokens') && Schema::hasColumn('device_tokens', 'device_name')) {
            Schema::table('device_tokens', function (Blueprint $table) {
                $table->dropColumn('device_name');
            });
        }

        Schema::dropIfExists('user_notification_preferences');
    }
};
