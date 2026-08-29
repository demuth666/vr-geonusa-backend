<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialViewEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'heritage_object' => [
                'id' => $this->heritageObject->id,
                'name' => $this->heritageObject->name,
                'slug' => $this->heritageObject->slug,
            ],
            'viewed_at' => $this->occurred_at->toISOString(),
        ];
    }
}
