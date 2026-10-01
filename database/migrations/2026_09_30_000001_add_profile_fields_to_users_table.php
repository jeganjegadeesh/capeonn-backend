<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('dob')->nullable()->after('phone');
            $table->string('gender', 20)->nullable()->after('dob');
            $table->text('address')->nullable()->after('gender');
            $table->string('emergency_contact_name')->nullable()->after('address');
            $table->string('emergency_contact_phone', 30)->nullable()->after('emergency_contact_name');
            $table->string('employment_type', 30)->default('full_time')->after('emergency_contact_phone');
            $table->date('probation_end_date')->nullable()->after('employment_type');
            $table->json('skills')->nullable()->after('probation_end_date');
            $table->json('certifications')->nullable()->after('skills');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'dob',
                'gender',
                'address',
                'emergency_contact_name',
                'emergency_contact_phone',
                'employment_type',
                'probation_end_date',
                'skills',
                'certifications',
            ]);
        });
    }
};
