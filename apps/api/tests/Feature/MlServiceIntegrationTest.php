<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\MachineLearning\Enums\MlInferenceStatus;
use App\Domain\MachineLearning\Models\MlInferenceRun;
use Database\Seeders\BorobudurSeeder;
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
            [
                'image' => $this->image(),
                'panorama_node_id' => PanoramaNode::query()->firstOrFail()->id,
                'camera_yaw' => 45.5,
                'camera_pitch' => -10,
                'camera_fov' => 90,
            ],
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

    /** A tiny but real image, well inside Laravel's own upload bounds. */
    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'viewport.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
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
