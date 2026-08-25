<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\RecordPanoramaVisit;
use App\Http\Requests\RecordPanoramaVisitRequest;
use App\Http\Resources\ActivityEventResource;
use Illuminate\Http\JsonResponse;

class PanoramaVisitController extends Controller
{
    public function store(
        RecordPanoramaVisitRequest $request,
        RecordPanoramaVisit $recordPanoramaVisit,
        int $id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $event = $recordPanoramaVisit->handle(
            $user,
            $id,
            $request->integer('panorama_node_id'),
            $request->header('X-Session-Write-Token'),
        );

        return response()->json([
            'data' => ActivityEventResource::make($event)->resolve($request),
        ], 201);
    }
}
