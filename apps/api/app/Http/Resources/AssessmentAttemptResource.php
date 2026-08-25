<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentAttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $answers = $this->answers->keyBy('assessment_item_id');

        return [
            'id' => $this->id,
            'type' => $this->instrument->type->value,
            'title' => $this->instrument->title,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'items' => $this->instrument->items->map(fn ($item) => [
                'id' => $item->id,
                'prompt' => $item->prompt,
                'position' => $item->position,
                'selected_option_id' => $answers->get($item->id)?->selected_option_id,
                'options' => $item->options->map(fn ($option) => [
                    'id' => $option->id,
                    'text' => $option->text,
                    'position' => $option->position,
                ])->values(),
            ])->values(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
