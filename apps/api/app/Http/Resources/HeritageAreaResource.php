<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HeritageAreaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'panorama_nodes' => $this->panoramaNodes->map(fn ($node) => [
                'id' => $node->id,
                'name' => $node->name,
                'slug' => $node->slug,
                'panorama_url' => $node->panorama_url,
            ])->values()->all(),
        ];
    }
}
