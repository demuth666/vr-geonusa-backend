<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $mapping = $this->heritageGeometryMapping;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'heritage_geometry_mapping' => [
                'id' => $mapping->id,
                'semantics' => $mapping->semantics,
                'heritage_object' => [
                    'id' => $mapping->heritageObject->id,
                    'name' => $mapping->heritageObject->name,
                    'slug' => $mapping->heritageObject->slug,
                ],
                'geometry_shape' => [
                    'id' => $mapping->geometryShape->id,
                    'name' => $mapping->geometryShape->name,
                    'slug' => $mapping->geometryShape->slug,
                ],
            ],
            'questions' => $this->questions->map(fn ($question) => [
                'id' => $question->id,
                'prompt' => $question->prompt,
                'position' => $question->position,
                'options' => $question->options->map(fn ($option) => [
                    'id' => $option->id,
                    'text' => $option->text,
                    'position' => $option->position,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
