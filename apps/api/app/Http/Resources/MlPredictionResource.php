<?php

namespace App\Http\Resources;

use App\Domain\Heritage\Models\HeritageObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MlPredictionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $heritageObjectsByClassKey = $this->modelVersion->classMappings
            ->keyBy('class_key')
            ->map(fn ($classMapping) => $classMapping->heritageObject);

        return [
            'model_version' => $this->modelVersion->version,
            'inference_ms' => $this->inference_ms,
            'total_latency_ms' => $this->total_latency_ms,
            'detections' => $this->detections->map(function ($detection) use ($heritageObjectsByClassKey) {
                $heritageObject = $heritageObjectsByClassKey->get($detection->class_key);

                return [
                    'class' => $detection->class_key,
                    'confidence' => $detection->confidence,
                    'bounding_box' => $detection->bounding_box,
                    'heritage_object' => $heritageObject ? $this->heritageObject($heritageObject) : null,
                    'geometry_mappings' => $heritageObject
                        ? $this->geometryMappings($heritageObject)
                        : [],
                ];
            })->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function heritageObject(HeritageObject $heritageObject): array
    {
        return [
            'id' => $heritageObject->id,
            'name' => $heritageObject->name,
            'slug' => $heritageObject->slug,
            'description' => $heritageObject->description,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function geometryMappings(HeritageObject $heritageObject): array
    {
        return $heritageObject->geometryMappings->map(fn ($mapping) => [
            'semantics' => $mapping->semantics,
            'geometry_shape' => [
                'id' => $mapping->geometryShape->id,
                'name' => $mapping->geometryShape->name,
                'slug' => $mapping->geometryShape->slug,
                'description' => $mapping->geometryShape->description,
            ],
        ])->values()->all();
    }
}
