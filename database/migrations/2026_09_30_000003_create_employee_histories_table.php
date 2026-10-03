<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 50); // joined, department_change, designation_change, role_change, reporting_change, promotion, note
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->date('effective_date');
            $table->foreignId('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'effective_date'], 'emp_hist_comp_user_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_histories');
    }
};
