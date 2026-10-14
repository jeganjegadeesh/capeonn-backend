<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('event', 64)->index();
            $table->text('description');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            // High-speed analytical and compliance filtering indexes
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'event']);
            $table->index(['company_id', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
