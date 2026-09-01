<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\MachineLearning\Models\MlDetection;
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

    public function test_ml_service_failures_do_not_persist_or_advance_the_session(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('ml-failure', 'a', exploration: true);
        Http::fake(['ml.test/*' => Http::response([], 500)]);
        Sanctum::actingAs($user);

        $this->predict($session->id, $writeToken)->assertStatus(502);

        Http::fake(['ml.test/*' => Http::response(['unexpected' => 'response'])]);
        $this->predict($session->id, $writeToken)->assertStatus(502);

        Http::fake(['ml.test/*' => Http::failedConnection()]);
        $this->predict($session->id, $writeToken)->assertStatus(502);

        $this->assertDatabaseCount('ml_models', 0);
        $this->assertDatabaseCount('ml_inference_runs', 0);
        $this->assertSame(LearningSessionPhase::Exploration, $session->fresh()->phase);
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
