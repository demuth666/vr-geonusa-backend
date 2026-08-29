<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_options', function (Blueprint $table) {
            $table->unique(['id', 'question_id']);
        });

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('quiz_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('selected_option_id');
            $table->boolean('is_correct');
            $table->timestamps();

            $table->unique(['quiz_attempt_id', 'question_id']);
            $table->foreign(['selected_option_id', 'question_id'])
                ->references(['id', 'question_id'])
                ->on('question_options')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_answers');
        Schema::dropIfExists('quiz_attempts');

        Schema::table('question_options', function (Blueprint $table) {
            $table->dropUnique(['id', 'question_id']);
        });
    }
};
