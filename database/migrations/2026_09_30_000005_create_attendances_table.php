<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('clock_in_at');
            $table->dateTime('clock_out_at')->nullable();
            $table->string('clock_in_ip', 45)->nullable();
            $table->string('clock_out_ip', 45)->nullable();
            $table->decimal('clock_in_latitude', 10, 7)->nullable();
            $table->decimal('clock_in_longitude', 10, 7)->nullable();
            $table->decimal('clock_out_latitude', 10, 7)->nullable();
            $table->decimal('clock_out_longitude', 10, 7)->nullable();
            $table->string('clock_in_location_name')->nullable();
            $table->string('clock_out_location_name')->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->string('flagged_reason')->nullable();
            $table->string('geofence_status', 30)->default('inside'); // inside, outside, unknown
            $table->string('status', 30)->default('present'); // present, late, half_day, absent, on_leave
            $table->unsignedInteger('total_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'is_flagged']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
