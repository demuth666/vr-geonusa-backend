<?php

namespace App\Domain\MachineLearning\Actions;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\MachineLearning\Enums\MlInferenceFailureReason;
use App\Domain\MachineLearning\Enums\MlInferenceStatus;
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
        } catch (ConnectionException $exception) {
            $this->recordFailure(
                $sessionId,
                $panoramaNodeId,
                $cameraYaw,
                $cameraPitch,
                $cameraFov,
                $startedAt,
                $this->classifyConnectionFailure($exception),
            );

            throw new HttpException(502, 'ML service is unavailable.');
        }

        if (! $response->successful()) {
            $this->recordFailure(
                $sessionId,
                $panoramaNodeId,
                $cameraYaw,
                $cameraPitch,
                $cameraFov,
                $startedAt,
                MlInferenceFailureReason::UpstreamError,
            );

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
            $this->recordFailure(
                $sessionId,
                $panoramaNodeId,
                $cameraYaw,
                $cameraPitch,
                $cameraFov,
                $startedAt,
                MlInferenceFailureReason::InvalidResponse,
                $this->safeModelVersion($response->json()),
            );

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

            $version = $this->resolveModelVersion($prediction['model_version']);
            $run = MlInferenceRun::create([
                'learning_session_id' => $session->id,
                'panorama_node_id' => $panoramaNodeId,
                'ml_model_version_id' => $version->id,
                'camera_yaw' => $cameraYaw,
                'camera_pitch' => $cameraPitch,
                'camera_fov' => $cameraFov,
                'inference_ms' => $prediction['inference_ms'],
                'total_latency_ms' => 0,
                'status' => MlInferenceStatus::Succeeded,
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

    private function recordFailure(
        int $sessionId,
        int $panoramaNodeId,
        float $cameraYaw,
        float $cameraPitch,
        float $cameraFov,
        int $startedAt,
        MlInferenceFailureReason $reason,
        ?string $modelVersion = null,
    ): void {
        DB::transaction(function () use (
            $sessionId,
            $panoramaNodeId,
            $cameraYaw,
            $cameraPitch,
            $cameraFov,
            $startedAt,
            $reason,
            $modelVersion,
        ) {
            MlInferenceRun::create([
                'learning_session_id' => $sessionId,
                'panorama_node_id' => $panoramaNodeId,
                'ml_model_version_id' => $modelVersion !== null
                    ? $this->resolveModelVersion($modelVersion)->id
                    : null,
                'camera_yaw' => $cameraYaw,
                'camera_pitch' => $cameraPitch,
                'camera_fov' => $cameraFov,
                'inference_ms' => null,
                'total_latency_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
                'status' => MlInferenceStatus::Failed,
                'failure_reason' => $reason,
            ]);
        });
    }

    private function resolveModelVersion(string $version): MlModelVersion
    {
        $model = MlModel::query()->createOrFirst(
            ['key' => 'geometry-detector'],
            ['name' => 'Geometry Detector'],
        );

        return MlModelVersion::query()->createOrFirst([
            'ml_model_id' => $model->id,
            'version' => $version,
        ]);
    }

    /** Extracts a safely validated model version from an otherwise invalid payload, without trusting the rest of the body. */
    private function safeModelVersion(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $validator = Validator::make($body, [
            'model_version' => ['required', 'string', 'max:255'],
        ]);

        return $validator->fails() ? null : $validator->validated()['model_version'];
    }

    private function classifyConnectionFailure(ConnectionException $exception): MlInferenceFailureReason
    {
        return str_contains(strtolower($exception->getMessage()), 'timed out')
            ? MlInferenceFailureReason::Timeout
            : MlInferenceFailureReason::ConnectionFailed;
    }
}
