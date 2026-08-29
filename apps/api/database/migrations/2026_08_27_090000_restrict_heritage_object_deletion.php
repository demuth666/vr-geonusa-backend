<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceDeleteRule('panorama_object_annotations', 'restrict');
        $this->replaceDeleteRule('heritage_geometry_mappings', 'restrict');
    }

    public function down(): void
    {
        $this->replaceDeleteRule('panorama_object_annotations', 'cascade');
        $this->replaceDeleteRule('heritage_geometry_mappings', 'cascade');
    }

    private function replaceDeleteRule(string $tableName, string $deleteRule): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($deleteRule): void {
            $table->dropForeign(['heritage_object_id']);

            $foreign = $table->foreign('heritage_object_id')
                ->references('id')
                ->on('heritage_objects');

            $deleteRule === 'restrict'
                ? $foreign->restrictOnDelete()
                : $foreign->cascadeOnDelete();
        });
    }
};
