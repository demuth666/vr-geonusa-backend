<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Models\LearningExperienceRevision;
use App\Domain\Research\Models\ResearchStudy;
use Illuminate\Support\Facades\DB;
use LogicException;

class ManageLearningExperienceRevision
{
    /** @param list<int> $panoramaNodeIds @param list<int> $heritageObjectIds @param list<int> $quizIds */
    public function createAndPublish(
        int $researchStudyId,
        array $panoramaNodeIds,
        array $heritageObjectIds,
        array $quizIds,
    ): LearningExperienceRevision {
        return $this->publish($this->createDraft(
            $researchStudyId,
            $panoramaNodeIds,
            $heritageObjectIds,
            $quizIds,
        ));
    }

    /** @param list<int> $panoramaNodeIds @param list<int> $heritageObjectIds @param list<int> $quizIds */
    public function createDraft(
        int $researchStudyId,
        array $panoramaNodeIds,
        array $heritageObjectIds,
        array $quizIds,
    ): LearningExperienceRevision {
        return DB::transaction(function () use ($researchStudyId, $panoramaNodeIds, $heritageObjectIds, $quizIds) {
            $study = ResearchStudy::query()->lockForUpdate()->findOrFail($researchStudyId);
            $revision = LearningExperienceRevision::create([
                'research_study_id' => $study->id,
                'version' => (int) LearningExperienceRevision::query()
                    ->where('research_study_id', $study->id)
                    ->max('version') + 1,
            ]);

            $this->syncRequirements($revision, $panoramaNodeIds, $heritageObjectIds, $quizIds);

            return $revision;
        });
    }

    /** @param list<int> $panoramaNodeIds @param list<int> $heritageObjectIds @param list<int> $quizIds */
    public function replaceRequirements(
        LearningExperienceRevision $revision,
        array $panoramaNodeIds,
        array $heritageObjectIds,
        array $quizIds,
    ): LearningExperienceRevision {
        return DB::transaction(function () use ($revision, $panoramaNodeIds, $heritageObjectIds, $quizIds) {
            $lockedRevision = LearningExperienceRevision::query()->lockForUpdate()->findOrFail($revision->id);

            if ($lockedRevision->status === 'published') {
                throw new LogicException('Published Learning Experience Revisions are immutable.');
            }

            $this->syncRequirements($lockedRevision, $panoramaNodeIds, $heritageObjectIds, $quizIds);

            return $lockedRevision;
        });
    }

    public function publish(LearningExperienceRevision $revision): LearningExperienceRevision
    {
        return DB::transaction(function () use ($revision) {
            $lockedRevision = LearningExperienceRevision::query()->lockForUpdate()->findOrFail($revision->id);

            if ($lockedRevision->status === 'published') {
                throw new LogicException('Learning Experience Revision is already published.');
            }

            if (! $lockedRevision->requiredPanoramaNodes()->exists()
                || ! $lockedRevision->requiredHeritageObjects()->exists()
                || ! $lockedRevision->requiredQuizzes()->exists()) {
                throw new LogicException('A published Learning Experience Revision needs all required activity types.');
            }

            $lockedRevision->forceFill([
                'status' => 'published',
                'published_at' => now(),
            ])->save();

            return $lockedRevision;
        });
    }

    /** @param list<int> $panoramaNodeIds @param list<int> $heritageObjectIds @param list<int> $quizIds */
    private function syncRequirements(
        LearningExperienceRevision $revision,
        array $panoramaNodeIds,
        array $heritageObjectIds,
        array $quizIds,
    ): void {
        $revision->requiredPanoramaNodes()->sync($panoramaNodeIds);
        $revision->requiredHeritageObjects()->sync($heritageObjectIds);
        $revision->requiredQuizzes()->sync($quizIds);
    }
}
