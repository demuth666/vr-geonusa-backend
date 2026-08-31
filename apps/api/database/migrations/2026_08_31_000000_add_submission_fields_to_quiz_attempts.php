<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->enum('status', ['draft', 'submitted'])->default('draft');
            $table->unsignedSmallInteger('score')->nullable();
            $table->timestamp('submitted_at')->nullable();
        });

        Schema::create('quiz_attempt_questions', function (Blueprint $table) {
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->primary(['quiz_attempt_id', 'question_id']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO quiz_attempt_questions (quiz_attempt_id, question_id)
            SELECT quiz_attempts.id, questions.id
            FROM quiz_attempts
            INNER JOIN questions ON questions.quiz_id = quiz_attempts.quiz_id
            SQL);
        DB::statement('ALTER TABLE quiz_attempts ADD CONSTRAINT quiz_attempts_score_range CHECK (score BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE quiz_attempts DROP CONSTRAINT quiz_attempts_score_range');
        Schema::dropIfExists('quiz_attempt_questions');

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['status', 'score', 'submitted_at']);
        });
    }
};
