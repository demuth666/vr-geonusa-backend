<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_instruments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_study_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['pretest', 'posttest', 'self_efficacy']);
            $table->string('title');
            $table->timestamps();

            $table->unique(['research_study_id', 'type']);
        });

        Schema::create('assessment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_instrument_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['assessment_instrument_id', 'position']);
        });

        Schema::create('assessment_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_item_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->unsignedSmallInteger('position');
            $table->boolean('is_correct')->default(false);
            $table->timestamps();

            $table->unique(['assessment_item_id', 'position']);
            $table->unique(['id', 'assessment_item_id']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX assessment_options_one_correct_per_item
            ON assessment_options (assessment_item_id)
            WHERE is_correct = true
            SQL);

        Schema::create('assessment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_instrument_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'submitted'])->default('draft');
            $table->unsignedSmallInteger('score')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['learning_session_id', 'assessment_instrument_id']);
        });

        Schema::create('assessment_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_item_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('selected_option_id');
            $table->boolean('is_correct')->nullable();
            $table->timestamps();

            $table->unique(['assessment_attempt_id', 'assessment_item_id']);
            $table->foreign(['selected_option_id', 'assessment_item_id'])
                ->references(['id', 'assessment_item_id'])
                ->on('assessment_options')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE assessment_attempts ADD CONSTRAINT assessment_attempts_score_range CHECK (score BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_answers');
        Schema::dropIfExists('assessment_attempts');
        Schema::dropIfExists('assessment_options');
        Schema::dropIfExists('assessment_items');
        Schema::dropIfExists('assessment_instruments');
    }
};
