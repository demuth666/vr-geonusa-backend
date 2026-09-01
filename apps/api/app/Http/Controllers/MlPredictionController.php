<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\MachineLearning\Actions\CreateMlPrediction;
use App\Http\Requests\CreateMlPredictionRequest;
use App\Http\Resources\MlPredictionResource;
use Illuminate\Http\JsonResponse;

class MlPredictionController extends Controller
{
    public function store(
        CreateMlPredictionRequest $request,
        CreateMlPrediction $createMlPrediction,
        int $id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $run = $createMlPrediction->handle(
            $user,
            $id,
            $request->file('image'),
            $request->integer('panorama_node_id'),
            $request->float('camera_yaw'),
            $request->float('camera_pitch'),
            $request->float('camera_fov'),
            $request->header('X-Session-Write-Token'),
        );

        return response()->json([
            'data' => MlPredictionResource::make($run)->resolve($request),
        ], 201);
    }
}
