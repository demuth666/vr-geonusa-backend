<?php

namespace App\Domain\MachineLearning\Actions;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\MachineLearning\Models\MlInferenceRun;
use App\Domain\MachineLearning\Models\MlModel;
use App\Domain\MachineLearning\Models\MlModelVersion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreateMlPrediction
{
    public function handle(
        User $user,
        int $sessionId,
        UploadedFile $image,
        int $panoramaNodeId,
        float $cameraYaw,
        float $cameraPitch,
        float $cameraFov,
        ?string $writeToken,
    ): MlInferenceRun {
        $startedAt = hrtime(true);
        $session = LearningSession::query()->ownedBy($user)->findOrFail($sessionId);
        $this->assertWritable($session, $writeToken);
        PanoramaNode::query()->findOrFail($panoramaNodeId);

        try {
            $response = Http::baseUrl((string) config('services.ml.url'))
                ->timeout((int) config('services.ml.timeout'))
                ->attach('image', $image->get(), $image->getClientOriginalName(), [
                    'Content-Type' => $image->getMimeType(),
                ])
                ->post('/v1/predict', [
                    'panorama_node_id' => $panoramaNodeId,
                    'camera_yaw' => $cameraYaw,
                    'camera_pitch' => $cameraPitch,
                    'camera_fov' => $cameraFov,
                ]);
        } catch (ConnectionException) {
            throw new HttpException(502, 'ML service is unavailable.');
        }

        if (! $response->successful()) {
            throw new HttpException(502, 'ML service rejected the prediction request.');
        }

        $validator = Validator::make($response->json() ?? [], [
            'model_version' => ['required', 'string', 'max:255'],
            'inference_ms' => ['required', 'integer', 'between:0,2147483647'],
            'detections' => ['required', 'array', 'max:100'],
            'detections.*.class' => ['required', 'string', 'max:255'],
            'detections.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'detections.*.bounding_box' => ['required', 'array', 'size:4'],
            'detections.*.bounding_box.*' => ['required', 'integer', 'between:0,4096'],
        ]);

        if ($validator->fails()) {
            throw new HttpException(502, 'ML service returned an invalid prediction.');
        }

        $prediction = $validator->validated();

        return DB::transaction(function () use (
            $user,
            $sessionId,
            $panoramaNodeId,
            $cameraYaw,
            $cameraPitch,
            $cameraFov,
            $writeToken,
            $prediction,
            $startedAt,
        ) {
            $session = LearningSession::query()
                ->ownedBy($user)
                ->lockForUpdate()
                ->findOrFail($sessionId);
            $this->assertWritable($session, $writeToken);

            $model = MlModel::firstOrCreate(
                ['key' => 'geometry-detector'],
                ['name' => 'Geometry Detector'],
            );
            $version = MlModelVersion::firstOrCreate([
                'ml_model_id' => $model->id,
                'version' => $prediction['model_version'],
            ]);
            $run = MlInferenceRun::create([
                'learning_session_id' => $session->id,
                'panorama_node_id' => $panoramaNodeId,
                'ml_model_version_id' => $version->id,
                'camera_yaw' => $cameraYaw,
                'camera_pitch' => $cameraPitch,
                'camera_fov' => $cameraFov,
                'inference_ms' => $prediction['inference_ms'],
                'total_latency_ms' => 0,
            ]);

            foreach ($prediction['detections'] as $detection) {
                $run->detections()->create([
                    'class_key' => $detection['class'],
                    'confidence' => $detection['confidence'],
                    'bounding_box' => $detection['bounding_box'],
                ]);
            }

            $run->forceFill([
                'total_latency_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            ])->save();

            $detectedClassKeys = collect($prediction['detections'])->pluck('class')->unique()->values();
            $version->load(['classMappings' => fn ($query) => $query
                ->whereIn('class_key', $detectedClassKeys)
                ->with('heritageObject.geometryMappings.geometryShape'),
            ]);

            return $run->setRelation('modelVersion', $version)
                ->load('detections');
        });
    }

    private function assertWritable(LearningSession $session, ?string $writeToken): void
    {
        if (! $session->hasValidWriteToken($writeToken)) {
            throw new AccessDeniedHttpException;
        }

        if ($session->phase !== LearningSessionPhase::Exploration) {
            throw new ConflictHttpException('ML predictions are only available during exploration.');
        }
    }
}
