<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing rows associate detector keys with heritage objects. A detector key now
        // names a geometry shape, so those development-only mappings are re-seeded instead
        // of being translated to an object that the key never identified on its own.
        DB::table('ml_class_mappings')->delete();

        Schema::table('ml_class_mappings', function (Blueprint $table) {
            $table->dropForeign(['heritage_object_id']);
            $table->dropColumn('heritage_object_id');
        });

        Schema::table('ml_class_mappings', function (Blueprint $table) {
            $table->foreignId('geometry_shape_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('ml_class_mappings')->delete();

        Schema::table('ml_class_mappings', function (Blueprint $table) {
            $table->dropForeign(['geometry_shape_id']);
            $table->dropColumn('geometry_shape_id');
        });

        Schema::table('ml_class_mappings', function (Blueprint $table) {
            $table->foreignId('heritage_object_id')->constrained()->restrictOnDelete();
        });
    }
};
