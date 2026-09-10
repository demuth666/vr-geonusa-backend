<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\MachineLearning\Enums\MlInferenceFailureReason;
use App\Domain\MachineLearning\Enums\MlInferenceStatus;
use App\Domain\MachineLearning\Models\MlClassMapping;
use App\Domain\MachineLearning\Models\MlDetection;
use App\Domain\MachineLearning\Models\MlInferenceRun;
use App\Domain\MachineLearning\Models\MlModel;
use App\Domain\MachineLearning\Models\MlModelVersion;
use Database\Seeders\BorobudurSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MlPredictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);
        config(['services.ml.url' => 'http://ml.test']);
    }

    public function test_predictions_require_authentication(): void
    {
        $this->withHeader('Accept', 'application/json')
            ->post('/api/v1/learning-sessions/1/ml-predictions')
            ->assertUnauthorized();
    }

    public function test_predictions_require_session_ownership_and_write_token(): void
    {
        [$studentA, $sessionA, $writeTokenA] = $this->createLearningSession('ml', 'a', exploration: true);
        [$studentB] = $this->createLearningSession('ml', 'b', exploration: true);

        Http::fake();
        Sanctum::actingAs($studentB);
        $this->predict($sessionA->id, $writeTokenA)->assertNotFound();

        Sanctum::actingAs($studentA);
        $this->predict($sessionA->id, 'invalid')->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('ml_inference_runs', 0);
    }

    public function test_predictions_require_the_exploration_phase(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('ml-phase', 'a');
        Http::fake();
        Sanctum::actingAs($user);

        $this->predict($session->id, $writeToken)->assertConflict();

        Http::assertNothingSent();
        $this->assertDatabaseCount('ml_inference_runs', 0);
    }

    public function test_predictions_validate_the_upload_and_camera_metadata(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('ml-validation', 'a', exploration: true);
        Sanctum::actingAs($user);

        $this->post(
            "/api/v1/learning-sessions/{$session->id}/ml-predictions",
            [
                'image' => UploadedFile::fake()->create('viewport.txt', 1, 'text/plain'),
                'panorama_node_id' => 999999,
                'camera_yaw' => 181,
                'camera_pitch' => -91,
                'camera_fov' => 0,
                'phase' => 'completed',
            ],
            [
                'Accept' => 'application/json',
                'X-Session-Write-Token' => $writeToken,
            ],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'image',
                'panorama_node_id',
                'camera_yaw',
                'camera_pitch',
                'camera_fov',
                'phase',
            ], 'error.details.fields');

        $this->post(
            "/api/v1/learning-sessions/{$session->id}/ml-predictions",
            [
                'image' => $this->oversizedImage(),
                'panorama_node_id' => PanoramaNode::query()->firstOrFail()->id,
                'camera_yaw' => 0,
                'camera_pitch' => 0,
                'camera_fov' => 90,
            ],
            [
                'Accept' => 'application/json',
                'X-Session-Write-Token' => $writeToken,
            ],
        )->assertJsonValidationErrors('image', 'error.details.fields');
    }

    public function test_successful_predictions_are_versioned_persisted_and_returned_without_advancing_the_session(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('ml-success', 'a', exploration: true);
        $node = PanoramaNode::query()->firstOrFail();
        Http::fake(['ml.test/*' => Http::response([
            'model_version' => 'dummy-v1',
            'inference_ms' => 10,
            'detections' => [[
                'class' => 'stupa',
                'confidence' => 0.95,
                'bounding_box' => [10, 20, 100, 120],
            ]],
        ])]);
        Sanctum::actingAs($user);

        $response = $this->predict($session->id, $writeToken, $node->id);

        $response
            ->assertCreated()
            ->assertJsonPath('data.model_version', 'dummy-v1')
            ->assertJsonPath('data.inference_ms', 10)
            ->assertJsonPath('data.detections.0.class', 'stupa')
            ->assertJsonPath('data.detections.0.confidence', 0.95)
            ->assertJsonPath('data.detections.0.bounding_box', [10, 20, 100, 120]);
        $this->assertIsInt($response->json('data.total_latency_ms'));

        $this->assertDatabaseHas('ml_model_versions', ['version' => 'dummy-v1']);
        $versionId = DB::table('ml_model_versions')
            ->where('version', 'dummy-v1')
            ->value('id');
        $this->assertDatabaseHas('ml_models', [
            'key' => 'geometry-detector',
            'name' => 'Geometry Detector',
        ]);
        $this->assertDatabaseHas('ml_inference_runs', [
            'learning_session_id' => $session->id,
            'panorama_node_id' => $node->id,
            'ml_model_version_id' => $versionId,
            'camera_yaw' => 45.5,
            'camera_pitch' => -10,
            'camera_fov' => 90,
            'inference_ms' => 10,
        ]);
        $this->assertDatabaseHas('ml_detections', [
            'class_key' => 'stupa',
            'confidence' => 0.95,
        ]);
        $this->assertSame([10, 20, 100, 120], MlDetection::query()->firstOrFail()->bounding_box);
        $this->assertSame(LearningSessionPhase::Exploration, $session->fresh()->phase);

        Http::assertSent(fn ($request) => $request->url() === 'http://ml.test/v1/predict');
    }

    public function test_ml_service_failures_record_a_failed_inference_run_and_do_not_advance_the_session(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('ml-failure', 'a', exploration: true);
        $node = PanoramaNode::query()->firstOrFail();
        Sanctum::actingAs($user);

        Http::fake(['ml.test/*' => Http::sequence()
            ->push([], 500)
            ->push(['unexpected' => 'response'])
            ->pushFailedConnection()
            ->pushFailedConnection('cURL error 28: Operation timed out'),
        ]);

        $this->predict($session->id, $writeToken, $node->id)->assertStatus(502);
        $this->predict($session->id, $writeToken, $node->id)->assertStatus(502);
        $this->predict($session->id, $writeToken, $node->id)->assertStatus(502);
        $this->predict($session->id, $writeToken, $node->id)->assertStatus(502);

        $this->assertDatabaseCount('ml_models', 0);
        $this->assertDatabaseCount('ml_inference_runs', 4);
        $this->assertDatabaseCount('ml_detections', 0);

        $runs = MlInferenceRun::query()->orderBy('id')->get();
        $expectedReasons = [
            MlInferenceFailureReason::UpstreamError,
            MlInferenceFailureReason::InvalidResponse,
            MlInferenceFailureReason::ConnectionFailed,
            MlInferenceFailureReason::Timeout,
        ];

        foreach ($runs as $index => $run) {
            $this->assertSame(MlInferenceStatus::Failed, $run->status);
            $this->assertSame($expectedReasons[$index], $run->failure_reason);
            $this->assertNull($run->ml_model_version_id);
            $this->assertNull($run->inference_ms);
            $this->assertSame($session->id, $run->learning_session_id);
            $this->assertSame($node->id, $run->panorama_node_id);
            $this->assertSame(45.5, $run->camera_yaw);
            $this->assertSame(-10.0, $run->camera_pitch);
            $this->assertSame(90.0, $run->camera_fov);
            $this->assertIsInt($run->total_latency_ms);
            $this->assertGreaterThanOrEqual(0, $run->total_latency_ms);
        }

        $this->assertSame(LearningSessionPhase::Exploration, $session->fresh()->phase);
    }

    public function test_a_malformed_payload_retains_a_safely_supplied_model_version_without_inventing_one(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('ml-invalid-payload', 'a', exploration: true);
        Http::fake(['ml.test/*' => Http::response([
            'model_version' => 'dummy-v1',
            'detections' => 'not-an-array',
        ])]);
        Sanctum::actingAs($user);

        $this->predict($session->id, $writeToken)->assertStatus(502);

        $this->assertDatabaseHas('ml_models', ['key' => 'geometry-detector']);
        $this->assertDatabaseHas('ml_model_versions', ['version' => 'dummy-v1']);
        $run = MlInferenceRun::query()->sole();
        $this->assertSame(MlInferenceStatus::Failed, $run->status);
        $this->assertSame(MlInferenceFailureReason::InvalidResponse, $run->failure_reason);
        $this->assertNotNull($run->ml_model_version_id);
        $this->assertSame('dummy-v1', $run->modelVersion->version);
        $this->assertSame(0, MlDetection::query()->count());

        $this->assertSame(LearningSessionPhase::Exploration, $session->fresh()->phase);
    }

    public function test_a_known_detected_class_resolves_to_its_heritage_object_and_geometry_mapping(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('ml-known', 'a', exploration: true);
        $version = $this->seedClassMapping('dummy-v1', 'stupa');
        Http::fake(['ml.test/*' => Http::response($this->predictionPayload('dummy-v1', 'stupa'))]);
        Sanctum::actingAs($user);

        $this->predict($session->id, $writeToken)
            ->assertCreated()
            ->assertJsonPath('data.detections.0.class', 'stupa')
            ->assertJsonPath('data.detections.0.heritage_object.slug', 'stupa')
            ->assertJsonPath('data.detections.0.heritage_object.name', 'Stupa')
            ->assertJsonPath('data.detections.0.geometry_mappings.0.semantics', 'didekati sebagai')
            ->assertJsonPath('data.detections.0.geometry_mappings.0.geometry_shape.slug', 'setengah-bola');

        $this->assertDatabaseHas('ml_class_mappings', [
            'ml_model_version_id' => $version->id,
            'class_key' => 'stupa',
        ]);
    }

    public function test_an_unknown_detected_class_remains_persisted_but_unresolved(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('ml-unknown', 'a', exploration: true);
        $this->seedClassMapping('dummy-v1', 'stupa');
        Http::fake(['ml.test/*' => Http::response($this->predictionPayload('dummy-v1', 'unrecognized-class'))]);
        Sanctum::actingAs($user);

        $this->predict($session->id, $writeToken)
            ->assertCreated()
            ->assertJsonPath('data.detections.0.class', 'unrecognized-class')
            ->assertJsonPath('data.detections.0.heritage_object', null)
            ->assertJsonPath('data.detections.0.geometry_mappings', []);

        $this->assertDatabaseHas('ml_detections', ['class_key' => 'unrecognized-class']);
    }

    public function test_resolution_is_scoped_to_the_reported_model_version(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('ml-versioned', 'a', exploration: true);
        $this->seedClassMapping('dummy-v1', 'stupa');
        Http::fake(['ml.test/*' => Http::response($this->predictionPayload('dummy-v2', 'stupa'))]);
        Sanctum::actingAs($user);

        $this->predict($session->id, $writeToken)
            ->assertCreated()
            ->assertJsonPath('data.model_version', 'dummy-v2')
            ->assertJsonPath('data.detections.0.class', 'stupa')
            ->assertJsonPath('data.detections.0.heritage_object', null)
            ->assertJsonPath('data.detections.0.geometry_mappings', []);
    }

    private function seedClassMapping(string $modelVersion, string $classKey): MlModelVersion
    {
        $model = MlModel::firstOrCreate(
            ['key' => 'geometry-detector'],
            ['name' => 'Geometry Detector'],
        );
        $version = MlModelVersion::firstOrCreate([
            'ml_model_id' => $model->id,
            'version' => $modelVersion,
        ]);
        MlClassMapping::create([
            'ml_model_version_id' => $version->id,
            'heritage_object_id' => HeritageObject::query()->where('slug', 'stupa')->sole()->id,
            'class_key' => $classKey,
        ]);

        return $version;
    }

    /** @return array<string, mixed> */
    private function predictionPayload(string $modelVersion, string $detectedClass): array
    {
        return [
            'model_version' => $modelVersion,
            'inference_ms' => 10,
            'detections' => [[
                'class' => $detectedClass,
                'confidence' => 0.95,
                'bounding_box' => [10, 20, 100, 120],
            ]],
        ];
    }

    private function predict(int $sessionId, string $writeToken, ?int $panoramaNodeId = null)
    {
        return $this->post(
            "/api/v1/learning-sessions/{$sessionId}/ml-predictions",
            [
                'image' => $this->image(),
                'panorama_node_id' => $panoramaNodeId ?? PanoramaNode::query()->firstOrFail()->id,
                'camera_yaw' => 45.5,
                'camera_pitch' => -10,
                'camera_fov' => 90,
            ],
            [
                'Accept' => 'application/json',
                'X-Session-Write-Token' => $writeToken,
            ],
        );
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'viewport.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }

    private function oversizedImage(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'viewport.png',
            $this->image()->get().str_repeat("\0", 5 * 1024 * 1024),
        );
    }
}
