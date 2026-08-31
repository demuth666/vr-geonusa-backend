<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_experience_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_study_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['research_study_id', 'version']);
        });

        Schema::create('learning_experience_revision_panorama_nodes', function (Blueprint $table) {
            $table->foreignId('learning_experience_revision_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('panorama_node_id')->constrained()->restrictOnDelete();

            $table->primary(['learning_experience_revision_id', 'panorama_node_id']);
        });

        Schema::create('learning_experience_revision_heritage_objects', function (Blueprint $table) {
            $table->foreignId('learning_experience_revision_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('heritage_object_id')->constrained()->restrictOnDelete();

            $table->primary(['learning_experience_revision_id', 'heritage_object_id']);
        });

        Schema::create('learning_experience_revision_quizzes', function (Blueprint $table) {
            $table->foreignId('learning_experience_revision_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained()->restrictOnDelete();

            $table->primary(['learning_experience_revision_id', 'quiz_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_published_learning_experience_requirement_changes()
                RETURNS trigger AS $$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM learning_experience_revisions
                        WHERE id = CASE WHEN TG_OP = 'DELETE'
                            THEN OLD.learning_experience_revision_id
                            ELSE NEW.learning_experience_revision_id
                        END
                        AND status = 'published'
                    ) THEN
                        RAISE EXCEPTION 'Published Learning Experience Revisions are immutable.';
                    END IF;

                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            foreach ([
                'learning_experience_revision_panorama_nodes',
                'learning_experience_revision_heritage_objects',
                'learning_experience_revision_quizzes',
            ] as $table) {
                DB::unprepared("CREATE TRIGGER prevent_published_requirement_changes\n"
                    ."BEFORE INSERT OR UPDATE OR DELETE ON {$table}\n"
                    .'FOR EACH ROW EXECUTE FUNCTION prevent_published_learning_experience_requirement_changes();');
            }
        }

        Schema::table('learning_sessions', function (Blueprint $table) {
            $table->foreignId('learning_experience_revision_id')
                ->nullable()
                ->after('research_participant_id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'learning_experience_revision_panorama_nodes',
                'learning_experience_revision_heritage_objects',
                'learning_experience_revision_quizzes',
            ] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS prevent_published_requirement_changes ON {$table};");
            }

            DB::unprepared('DROP FUNCTION IF EXISTS prevent_published_learning_experience_requirement_changes();');
        }

        Schema::table('learning_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('learning_experience_revision_id');
        });

        Schema::dropIfExists('learning_experience_revision_quizzes');
        Schema::dropIfExists('learning_experience_revision_heritage_objects');
        Schema::dropIfExists('learning_experience_revision_panorama_nodes');
        Schema::dropIfExists('learning_experience_revisions');
    }
};
