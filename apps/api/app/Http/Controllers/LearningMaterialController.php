<?php

namespace App\Http\Controllers;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\RecordMaterialView;
use App\Http\Requests\RecordMaterialViewRequest;
use App\Http\Resources\LearningMaterialResource;
use App\Http\Resources\MaterialViewEventResource;
use Illuminate\Http\JsonResponse;

class LearningMaterialController extends Controller
{
    public function show(int $id): LearningMaterialResource
    {
        return LearningMaterialResource::make(
            HeritageObject::query()
                ->with(['geometryMappings.geometryShape', 'geometryMappings.learningObjectives'])
                ->findOrFail($id),
        );
    }

    public function viewed(
        RecordMaterialViewRequest $request,
        RecordMaterialView $recordMaterialView,
        int $id,
        int $objectId,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $event = $recordMaterialView->handle(
            $user,
            $id,
            $objectId,
            $request->header('X-Session-Write-Token'),
        );

        return response()->json([
            'data' => MaterialViewEventResource::make($event)->resolve($request),
        ], 201);
    }
}
