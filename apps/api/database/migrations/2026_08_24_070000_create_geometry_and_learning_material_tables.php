<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heritage_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('heritage_site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['heritage_site_id', 'slug']);
        });

        Schema::create('panorama_object_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panorama_node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('heritage_object_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['panorama_node_id', 'heritage_object_id']);
        });

        Schema::create('geometry_shapes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('heritage_geometry_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('heritage_object_id')->constrained()->cascadeOnDelete();
            $table->foreignId('geometry_shape_id')->constrained()->cascadeOnDelete();
            $table->string('semantics', 50);
            $table->timestamps();

            $table->unique(['heritage_object_id', 'geometry_shape_id']);
        });

        Schema::create('learning_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('heritage_geometry_mapping_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('material_content');
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['heritage_geometry_mapping_id', 'position']);
        });

        Schema::table('activity_events', function (Blueprint $table) {
            $table->foreignId('panorama_node_id')->nullable()->change();
            $table->foreignId('heritage_object_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            $table->index(['learning_session_id', 'type', 'heritage_object_id']);
        });
    }

    public function down(): void
    {
        DB::table('activity_events')->whereNotNull('heritage_object_id')->delete();

        Schema::table('activity_events', function (Blueprint $table) {
            $table->dropIndex(['learning_session_id', 'type', 'heritage_object_id']);
            $table->dropConstrainedForeignId('heritage_object_id');
            $table->foreignId('panorama_node_id')->nullable(false)->change();
        });

        Schema::dropIfExists('learning_objectives');
        Schema::dropIfExists('heritage_geometry_mappings');
        Schema::dropIfExists('geometry_shapes');
        Schema::dropIfExists('panorama_object_annotations');
        Schema::dropIfExists('heritage_objects');
    }
};
