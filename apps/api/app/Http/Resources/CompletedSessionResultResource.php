<?php

namespace App\Http\Resources;

use App\Domain\Learning\Models\ActivityEvent;
use App\Domain\Research\Enums\AssessmentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompletedSessionResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $scoreFor = fn (AssessmentType $type) => $this->assessmentAttempts
            ->first(fn ($attempt) => $attempt->status === 'submitted' && $attempt->instrument->type === $type)
            ?->score;

        return [
            'id' => $this->id,
            'phase' => $this->phase->value,
            'pretest_score' => $scoreFor(AssessmentType::Pretest),
            'posttest_score' => $scoreFor(AssessmentType::Posttest),
            'activity_summary' => [
                'panoramas_visited' => $this->activityEvents
                    ->where('type', ActivityEvent::PANORAMA_VISITED)
                    ->pluck('panorama_node_id')
                    ->unique()
                    ->count(),
                'materials_viewed' => $this->activityEvents
                    ->where('type', ActivityEvent::MATERIAL_VIEWED)
                    ->pluck('heritage_object_id')
                    ->unique()
                    ->count(),
                'micro_quizzes_completed' => $this->quizAttempts
                    ->where('status', 'submitted')
                    ->pluck('quiz_id')
                    ->unique()
                    ->count(),
            ],
        ];
    }
}
