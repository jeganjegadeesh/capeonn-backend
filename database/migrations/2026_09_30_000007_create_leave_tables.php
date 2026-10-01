<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20); // CL, SL, PL, LOP, etc.
            $table->text('description')->nullable();
            $table->decimal('annual_days', 4, 1)->default(12.0);
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('total_days', 4, 1)->default(0.0);
            $table->decimal('used_days', 4, 1)->default(0.0);
            $table->decimal('pending_days', 4, 1)->default(0.0);
            $table->timestamps();

            $table->unique(['user_id', 'leave_type_id', 'year']);
            $table->index(['company_id', 'year']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_half_day')->default(false);
            $table->string('half_day_type', 20)->nullable(); // first_half, second_half
            $table->decimal('days_count', 4, 1)->default(1.0);
            $table->text('reason');
            $table->string('status', 30)->default('pending'); // pending, approved, rejected, cancelled
            $table->foreignId('actioned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('action_remarks')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['company_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
    }
};
