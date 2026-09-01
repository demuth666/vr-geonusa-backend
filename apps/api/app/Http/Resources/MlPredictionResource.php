<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MlPredictionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'model_version' => $this->modelVersion->version,
            'inference_ms' => $this->inference_ms,
            'total_latency_ms' => $this->total_latency_ms,
            'detections' => $this->detections->map(fn ($detection) => [
                'class' => $detection->class_key,
                'confidence' => $detection->confidence,
                'bounding_box' => $detection->bounding_box,
            ])->all(),
        ];
    }
}
