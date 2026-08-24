<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_studies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('research_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_study_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('respondent_code')->unique();
            $table->timestamps();

            $table->unique(['research_study_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_participants');
        Schema::dropIfExists('research_studies');
    }
};
