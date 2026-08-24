<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_participant_id')->constrained()->restrictOnDelete();
            $table->enum('phase', [
                'pretest',
                'exploration',
                'posttest',
                'self_efficacy',
                'completed',
            ])->default('pretest');
            $table->char('write_token_hash', 64);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX learning_sessions_one_active_per_participant
            ON learning_sessions (research_participant_id)
            WHERE phase <> 'completed'
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_sessions');
    }
};
