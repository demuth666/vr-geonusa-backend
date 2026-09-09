<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_class_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ml_model_version_id')->constrained('ml_model_versions')->cascadeOnDelete();
            $table->foreignId('heritage_object_id')->constrained()->restrictOnDelete();
            $table->string('class_key');
            $table->timestamps();

            $table->unique(['ml_model_version_id', 'class_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_class_mappings');
    }
};
