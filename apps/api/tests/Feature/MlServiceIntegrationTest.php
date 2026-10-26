<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\MachineLearning\Enums\MlInferenceStatus;
use App\Domain\MachineLearning\Models\MlInferenceRun;
use App\Filament\Resources\MlInferenceRuns\MlInferenceRunResource;
use Database\Seeders\BorobudurSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Throwable;
use Tests\TestCase;

/**
 * Exercises the composed stack rather than a faked ML service: a prediction travels
 * Laravel → ML service → Laravel and has to answer within the ML timeout Laravel is
 * configured with. It is about the wiring, so it skips where no ML service answers —
 * run it through the stack, as the README's Verify section does.
 */
class MlServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const STOCK_DETECTOR_VERSION = 'yolov8n-coco-v1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);

        try {
            Http::timeout(2)->get($this->mlHealthUrl());
        } catch (ConnectionException) {
            $this->markTestSkipped('the ML service is not reachable from here.');
        }
    }

    public function test_a_prediction_through_the_stack_answers_within_the_configured_ml_timeout(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('ml-integration', 'a', exploration: true);
        Sanctum::actingAs($user);

        $timeoutMs = (int) config('services.ml.timeout') * 1000;
        $startedAt = hrtime(true);

        $response = $this->post(
            "/api/v1/learning-sessions/{$session->id}/ml-predictions",
            $this->predictionPayload($this->image(), PanoramaNode::query()->firstOrFail()->id),
            [
                'Accept' => 'application/json',
                'X-Session-Write-Token' => $writeToken,
            ],
        );

        $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $this->assertSame(201, $response->status(), sprintf(
            'the stacked prediction did not succeed: %s | ML health: %s',
            $response->getContent(),
            $this->mlHealth(),
        ));
        $this->assertLessThan($timeoutMs, $elapsedMs, 'the stack answered later than the ML timeout.');

        $run = MlInferenceRun::query()->sole();
        $this->assertSame(MlInferenceStatus::Succeeded, $run->status);
        $this->assertLessThan($timeoutMs, $run->total_latency_ms);
        $this->assertIsInt($run->inference_ms);
        $this->assertNotNull($run->modelVersion);
        $this->assertSame($run->modelVersion->version, $response->json('data.model_version'));
    }

    public function test_the_real_detector_returns_and_persists_unresolved_detections_without_advancing_the_session(): void
    {
        $health = Http::timeout(2)->get($this->mlHealthUrl())->json('data');

        if (($health['detector'] ?? null) !== 'yolo') {
            $this->markTestSkipped('the ML service is not serving the real detector.');
        }

        $this->assertSame('ok', $health['status'] ?? null, 'the real detector is not healthy.');

        if (($health['model_version'] ?? null) !== self::STOCK_DETECTOR_VERSION) {
            $this->markTestSkipped('the ML service is not serving the stock COCO detector.');
        }

        [$user, $session, $writeToken] = $this->createLearningSession('ml-real-detector', 'a', exploration: true);
        $node = PanoramaNode::query()->firstOrFail();
        $before = $session->fresh();
        Sanctum::actingAs($user);

        $response = $this->post(
            "/api/v1/learning-sessions/{$session->id}/ml-predictions",
            $this->predictionPayload($this->realDetectorImage(), $node->id),
            [
                'Accept' => 'application/json',
                'X-Session-Write-Token' => $writeToken,
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.model_version', self::STOCK_DETECTOR_VERSION)
            ->assertJsonStructure([
                'data' => [
                    'model_version',
                    'inference_ms',
                    'total_latency_ms',
                    'detections' => [
                        ['class', 'confidence', 'bounding_box', 'heritage_object', 'geometry_mappings'],
                    ],
                ],
            ]);

        $detections = $response->json('data.detections');
        $this->assertNotEmpty($detections, 'the real detector did not report any object.');
        $this->assertContains('person', array_column($detections, 'class'));

        foreach ($detections as $detection) {
            $this->assertIsString($detection['class']);
            $this->assertGreaterThan(0, $detection['confidence']);
            $this->assertLessThanOrEqual(1, $detection['confidence']);
            $this->assertCount(4, $detection['bounding_box']);
            $this->assertSame([], $detection['geometry_mappings']);
            $this->assertNull($detection['heritage_object']);
        }

        $run = MlInferenceRun::query()->with(['modelVersion', 'detections'])->sole();
        $this->assertSame(MlInferenceStatus::Succeeded, $run->status);
        $this->assertSame(self::STOCK_DETECTOR_VERSION, $run->modelVersion->version);
        $this->assertSame($session->id, $run->learning_session_id);
        $this->assertSame($node->id, $run->panorama_node_id);
        $this->assertSame(45.5, $run->camera_yaw);
        $this->assertSame(-10.0, $run->camera_pitch);
        $this->assertSame(90.0, $run->camera_fov);
        $this->assertIsInt($run->inference_ms);
        $this->assertGreaterThanOrEqual(0, $run->total_latency_ms);
        $this->assertCount(count($detections), $run->detections);
        $this->assertEqualsCanonicalizing(
            array_map(fn (array $detection): array => [
                'class_key' => $detection['class'],
                'confidence' => $detection['confidence'],
                'bounding_box' => $detection['bounding_box'],
            ], $detections),
            $run->detections->map(fn ($detection): array => [
                'class_key' => $detection->class_key,
                'confidence' => $detection->confidence,
                'bounding_box' => $detection->bounding_box,
            ])->all(),
        );

        // The stock detector's COCO keys have no authored mapping for this version, so they
        // stay recorded and unresolved rather than being attached to unrelated heritage.
        $this->assertSame(0, $run->modelVersion->classMappings()->count());

        $after = $session->fresh();
        $this->assertSame(LearningSessionPhase::Exploration, $after->phase);
        $this->assertSame($before->write_token_hash, $after->write_token_hash);
        $this->assertSame($before->updated_at->toISOString(), $after->updated_at->toISOString());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->createUser(UserRole::SuperAdmin), 'web');
        $this->get(MlInferenceRunResource::getUrl('index'))
            ->assertOk()
            ->assertSee($health['model_version'])
            ->assertSee($detections[0]['class']);
    }

    /**
     * The request body both stacked predictions share; only the image and node vary.
     *
     * @return array<string, mixed>
     */
    private function predictionPayload(UploadedFile $image, int $panoramaNodeId): array
    {
        return [
            'image' => $image,
            'panorama_node_id' => $panoramaNodeId,
            'camera_yaw' => 45.5,
            'camera_pitch' => -10,
            'camera_fov' => 90,
        ];
    }

    /** A tiny but real image, well inside Laravel's own upload bounds. */
    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'viewport.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }

    /** A viewport with objects the stock COCO detector reports, from the Ultralytics test assets. */
    private function realDetectorImage(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'viewport.jpg',
            file_get_contents(base_path('tests/Fixtures/real-detector-viewport.jpg')),
        );
    }

    private function mlHealthUrl(): string
    {
        return config('services.ml.url').'/health';
    }

    /** What the ML service says about itself, for the failure message of this test. */
    private function mlHealth(): string
    {
        try {
            return Http::timeout(2)->get($this->mlHealthUrl())->body();
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
    }
}
