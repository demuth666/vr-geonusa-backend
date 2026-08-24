<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heritage_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('cover_image_url');
            $table->timestamps();
        });

        Schema::create('heritage_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('heritage_site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['heritage_site_id', 'slug']);
        });

        Schema::create('panorama_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('heritage_area_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('panorama_url');
            $table->timestamps();

            $table->unique(['heritage_area_id', 'slug']);
        });

        Schema::create('panorama_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_node_id')->constrained('panorama_nodes')->cascadeOnDelete();
            $table->foreignId('target_node_id')->constrained('panorama_nodes')->cascadeOnDelete();
            $table->decimal('yaw', 7, 3);
            $table->decimal('pitch', 6, 3)->default(0);
            $table->string('label');
            $table->timestamps();

            $table->unique(['source_node_id', 'target_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panorama_links');
        Schema::dropIfExists('panorama_nodes');
        Schema::dropIfExists('heritage_areas');
        Schema::dropIfExists('heritage_sites');
    }
};
