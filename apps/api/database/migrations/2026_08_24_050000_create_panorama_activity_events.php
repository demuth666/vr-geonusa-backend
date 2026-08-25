<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_sessions', function (Blueprint $table) {
            $table->foreignId('current_panorama_node_id')
                ->nullable()
                ->constrained('panorama_nodes')
                ->restrictOnDelete();
        });

        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_session_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->foreignId('panorama_node_id')->constrained()->restrictOnDelete();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['learning_session_id', 'type', 'panorama_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');

        Schema::table('learning_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_panorama_node_id');
        });
    }
};
