<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_file_id')->constrained('project_files')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type');
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['project_file_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_file_versions');
    }
};
