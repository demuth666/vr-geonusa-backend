<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_inference_runs', function (Blueprint $table) {
            $table->foreignId('ml_model_version_id')
                ->nullable()
                ->change();
            $table->unsignedInteger('inference_ms')
                ->nullable()
                ->change();
            $table->string('status')->default('succeeded')->after('total_latency_ms');
            $table->string('failure_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        // inference_ms and ml_model_version_id are left nullable: recorded failed
        // runs may hold nulls there, which would violate a restored NOT NULL constraint.
        Schema::table('ml_inference_runs', function (Blueprint $table) {
            $table->dropColumn(['status', 'failure_reason']);
        });
    }
};
