<?php

namespace App\Http\Resources;

use App\Domain\Geometry\Models\GeometryShape;
use App\Domain\Heritage\Models\HeritageObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MlPredictionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $geometryShapesByClassKey = $this->modelVersion->classMappings
            ->keyBy('class_key')
            ->map(fn ($classMapping) => $classMapping->geometryShape);

        $heritageMappingsByShapeId = [];

        foreach ($this->panoramaNode->annotations as $annotation) {
            $heritageObject = $annotation->heritageObject;

            if ($heritageObject === null) {
                continue;
            }

            foreach ($heritageObject->geometryMappings as $mapping) {
                $heritageMappingsByShapeId[$mapping->geometry_shape_id][] = [
                    'heritage_object' => $this->heritageObject($heritageObject),
                    'semantics' => $mapping->semantics,
                ];
            }
        }

        return [
            'model_version' => $this->modelVersion->version,
            'inference_ms' => $this->inference_ms,
            'total_latency_ms' => $this->total_latency_ms,
            'detections' => $this->detections->map(function ($detection) use ($geometryShapesByClassKey, $heritageMappingsByShapeId) {
                $geometryShape = $geometryShapesByClassKey->get($detection->class_key);

                return [
                    'class' => $detection->class_key,
                    'confidence' => $detection->confidence,
                    'bounding_box' => $detection->bounding_box,
                    'geometry_shape' => $geometryShape ? $this->geometryShape($geometryShape) : null,
                    'heritage_mappings' => $geometryShape
                        ? ($heritageMappingsByShapeId[$geometryShape->id] ?? [])
                        : [],
                ];
            })->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function geometryShape(GeometryShape $geometryShape): array
    {
        return [
            'id' => $geometryShape->id,
            'name' => $geometryShape->name,
            'slug' => $geometryShape->slug,
            'description' => $geometryShape->description,
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
}
