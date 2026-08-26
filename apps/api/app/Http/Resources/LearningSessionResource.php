<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'respondent_code' => $this->researchParticipant->respondent_code,
            'phase' => $this->phase->value,
            'current_panorama_node' => $this->currentPanoramaNode ? [
                'id' => $this->currentPanoramaNode->id,
                'name' => $this->currentPanoramaNode->name,
                'slug' => $this->currentPanoramaNode->slug,
                'panorama_url' => $this->currentPanoramaNode->panoramaUrl(),
            ] : null,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
