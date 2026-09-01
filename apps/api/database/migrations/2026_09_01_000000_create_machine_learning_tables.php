<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_models', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('ml_model_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ml_model_id')->constrained('ml_models')->restrictOnDelete();
            $table->string('version');
            $table->timestamps();

            $table->unique(['ml_model_id', 'version']);
        });

        Schema::create('ml_inference_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('panorama_node_id')->constrained()->restrictOnDelete();
            $table->foreignId('ml_model_version_id')->constrained('ml_model_versions')->restrictOnDelete();
            $table->double('camera_yaw');
            $table->double('camera_pitch');
            $table->double('camera_fov');
            $table->unsignedInteger('inference_ms');
            $table->unsignedInteger('total_latency_ms');
            $table->timestamps();
        });

        Schema::create('ml_detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ml_inference_run_id')->constrained()->cascadeOnDelete();
            $table->string('class_key');
            $table->decimal('confidence', 5, 4);
            $table->json('bounding_box');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_detections');
        Schema::dropIfExists('ml_inference_runs');
        Schema::dropIfExists('ml_model_versions');
        Schema::dropIfExists('ml_models');
    }
};
