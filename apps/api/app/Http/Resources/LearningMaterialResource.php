<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningMaterialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'heritage_object' => [
                'id' => $this->id,
                'name' => $this->name,
                'slug' => $this->slug,
                'description' => $this->description,
            ],
            'geometry_mappings' => $this->geometryMappings->map(fn ($mapping) => [
                'semantics' => $mapping->semantics,
                'geometry_shape' => [
                    'id' => $mapping->geometryShape->id,
                    'name' => $mapping->geometryShape->name,
                    'slug' => $mapping->geometryShape->slug,
                    'description' => $mapping->geometryShape->description,
                ],
                'learning_objectives' => $mapping->learningObjectives->map(fn ($objective) => [
                    'id' => $objective->id,
                    'title' => $objective->title,
                    'content' => $objective->material_content,
                    'position' => $objective->position,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
