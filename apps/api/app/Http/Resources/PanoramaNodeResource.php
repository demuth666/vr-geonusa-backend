<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PanoramaNodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'panorama_url' => $this->panorama_url,
            'area' => [
                'id' => $this->heritageArea->id,
                'name' => $this->heritageArea->name,
                'slug' => $this->heritageArea->slug,
                'heritage_site' => [
                    'id' => $this->heritageArea->heritageSite->id,
                    'name' => $this->heritageArea->heritageSite->name,
                    'slug' => $this->heritageArea->heritageSite->slug,
                ],
            ],
            'links' => $this->outgoingLinks->map(fn ($link) => [
                'id' => $link->id,
                'label' => $link->label,
                'yaw' => $link->yaw,
                'pitch' => $link->pitch,
                'target' => [
                    'id' => $link->targetNode->id,
                    'name' => $link->targetNode->name,
                    'slug' => $link->targetNode->slug,
                    'panorama_url' => $link->targetNode->panorama_url,
                ],
            ])->values()->all(),
        ];
    }
}
