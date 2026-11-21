<?php

namespace Database\Seeders;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Learning\Actions\ManageLearningExperienceRevision;
use App\Domain\Learning\Models\LearningExperienceRevision;
use App\Domain\Learning\Models\Quiz;
use App\Domain\MachineLearning\Models\MlClassMapping;
use App\Domain\MachineLearning\Models\MlModel;
use App\Domain\MachineLearning\Models\MlModelVersion;
use App\Domain\Research\Models\ResearchParticipant;
use App\Domain\Research\Models\ResearchStudy;
use Illuminate\Database\Seeder;

/**
 * Development/demo data needed to exercise the student API end to end.
 *
 * The base seeders create content, but a student cannot start a session
 * until a ResearchParticipant and a published LearningExperienceRevision
 * exist. The dummy ML detector (class key "stupa", model version "dummy-v1")
 * also needs a class mapping to resolve a HeritageObject.
 *
 * This seeder is local/testing only and is intentionally idempotent.
 */
class DemoLearningSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $study = ResearchStudy::query()
            ->where('name', 'VR GeoNusa Borobudur Study')
            ->first();

        $profile = StudentProfile::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'student@example.test'))
            ->first();

        $node = PanoramaNode::query()->where('slug', 'stupa-induk')->first();
        $object = HeritageObject::query()->where('slug', 'stupa')->first();
        $quiz = Quiz::query()->orderBy('id')->first();

        if (! $study || ! $profile || ! $node || ! $object || ! $quiz) {
            return;
        }

        ResearchParticipant::firstOrCreate([
            'research_study_id' => $study->id,
            'student_profile_id' => $profile->id,
        ]);

        $hasPublishedRevision = LearningExperienceRevision::query()
            ->where('research_study_id', $study->id)
            ->where('status', 'published')
            ->exists();

        if (! $hasPublishedRevision) {
            app(ManageLearningExperienceRevision::class)->createAndPublish(
                $study->id,
                [$node->id],
                [$object->id],
                [$quiz->id],
            );
        }

        $model = MlModel::query()->firstOrCreate(
            ['key' => 'geometry-detector'],
            ['name' => 'Geometry Detector'],
        );

        $version = MlModelVersion::query()->firstOrCreate([
            'ml_model_id' => $model->id,
            'version' => 'dummy-v1',
        ]);

        MlClassMapping::query()->firstOrCreate(
            [
                'ml_model_version_id' => $version->id,
                'class_key' => 'stupa',
            ],
            ['heritage_object_id' => $object->id],
        );
    }
}
