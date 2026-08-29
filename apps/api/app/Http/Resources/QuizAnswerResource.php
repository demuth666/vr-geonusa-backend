<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizAnswerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_id' => $this->question_id,
            'selected_option_id' => $this->selected_option_id,
            'is_correct' => $this->is_correct,
            'feedback' => $this->selectedOption->feedback,
            'answered_at' => $this->created_at->toISOString(),
        ];
    }
}
