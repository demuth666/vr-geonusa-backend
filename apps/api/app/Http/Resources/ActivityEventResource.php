<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'panorama_node' => [
                'id' => $this->panoramaNode->id,
                'name' => $this->panoramaNode->name,
                'slug' => $this->panoramaNode->slug,
                'panorama_url' => $this->panoramaNode->panorama_url,
            ],
            'visited_at' => $this->occurred_at->toISOString(),
        ];
    }
}
