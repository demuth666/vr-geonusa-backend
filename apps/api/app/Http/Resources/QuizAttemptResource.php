<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizAttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quiz_id' => $this->quiz_id,
            'status' => $this->status,
            'score' => $this->score,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
