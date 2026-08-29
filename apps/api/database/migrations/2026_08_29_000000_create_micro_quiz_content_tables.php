<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('heritage_geometry_mapping_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['quiz_id', 'position']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->unsignedSmallInteger('position');
            $table->boolean('is_correct')->default(false);
            $table->text('feedback');
            $table->timestamps();

            $table->unique(['question_id', 'position']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX question_options_one_correct_per_question
            ON question_options (question_id)
            WHERE is_correct = true
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('quizzes');
    }
};
