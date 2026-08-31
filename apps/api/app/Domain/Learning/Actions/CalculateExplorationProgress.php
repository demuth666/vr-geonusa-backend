<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Models\ActivityEvent;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Learning\Models\QuizAttempt;
use LogicException;

class CalculateExplorationProgress
{
    /** @return array{panorama_visits: list<array{panorama_node_id: int, completed: bool}>, learning_materials: list<array{heritage_object_id: int, completed: bool}>, micro_quizzes: list<array{quiz_id: int, completed: bool}>, completed: bool} */
    public function handle(LearningSession $session): array
    {
        $revision = $session->learningExperienceRevision;

        if (! $revision) {
            throw new LogicException('Learning session has no Learning Experience Revision.');
        }

        $panoramaNodeIds = array_map('intval', $revision->requiredPanoramaNodes()->pluck('panorama_nodes.id')->all());
        $heritageObjectIds = array_map('intval', $revision->requiredHeritageObjects()->pluck('heritage_objects.id')->all());
        $quizIds = array_map('intval', $revision->requiredQuizzes()->pluck('quizzes.id')->all());
        $visitedNodeIds = array_map('intval', ActivityEvent::query()
            ->where('learning_session_id', $session->id)
            ->where('type', ActivityEvent::PANORAMA_VISITED)
            ->whereIn('panorama_node_id', $panoramaNodeIds)
            ->distinct()
            ->pluck('panorama_node_id')
            ->all());
        $viewedObjectIds = array_map('intval', ActivityEvent::query()
            ->where('learning_session_id', $session->id)
            ->where('type', ActivityEvent::MATERIAL_VIEWED)
            ->whereIn('heritage_object_id', $heritageObjectIds)
            ->distinct()
            ->pluck('heritage_object_id')
            ->all());
        $completedQuizIds = array_map('intval', QuizAttempt::query()
            ->where('learning_session_id', $session->id)
            ->where('status', 'submitted')
            ->whereIn('quiz_id', $quizIds)
            ->distinct()
            ->pluck('quiz_id')
            ->all());

        $panoramaVisits = array_map(
            fn (int $id) => ['panorama_node_id' => $id, 'completed' => in_array($id, $visitedNodeIds, true)],
            $panoramaNodeIds,
        );
        $learningMaterials = array_map(
            fn (int $id) => ['heritage_object_id' => $id, 'completed' => in_array($id, $viewedObjectIds, true)],
            $heritageObjectIds,
        );
        $microQuizzes = array_map(
            fn (int $id) => ['quiz_id' => $id, 'completed' => in_array($id, $completedQuizIds, true)],
            $quizIds,
        );

        return [
            'panorama_visits' => $panoramaVisits,
            'learning_materials' => $learningMaterials,
            'micro_quizzes' => $microQuizzes,
            'completed' => ! in_array(false, array_column($panoramaVisits, 'completed'), true)
                && ! in_array(false, array_column($learningMaterials, 'completed'), true)
                && ! in_array(false, array_column($microQuizzes, 'completed'), true),
        ];
    }
}
