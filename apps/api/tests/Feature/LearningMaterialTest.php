<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\ActivityEvent;
use App\Domain\Learning\Models\LearningSession;
use Database\Seeders\BorobudurSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LearningMaterialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3', ['url' => 'http://storage.test/vr-geonusa-dev']);
        $this->seed(BorobudurSeeder::class);
    }

    public function test_borobudur_material_preserves_heritage_and_geometry_boundaries(): void
    {
        $stupa = HeritageObject::query()->where('slug', 'stupa')->sole();
        $node = PanoramaNode::query()->where('slug', 'stupa-induk')->sole();

        $this->assertFalse(Schema::hasColumn('heritage_objects', 'geometry_shape_id'));
        $this->assertFalse(Schema::hasColumn('panorama_object_annotations', 'geometry_shape_id'));
        $this->assertDatabaseHas('panorama_object_annotations', [
            'panorama_node_id' => $node->id,
            'heritage_object_id' => $stupa->id,
        ]);
        $this->assertDatabaseHas('heritage_geometry_mappings', [
            'heritage_object_id' => $stupa->id,
            'semantics' => 'didekati sebagai',
        ]);

        $this->getJson("/api/v1/panorama-nodes/{$node->id}")
            ->assertOk()
            ->assertJsonPath('data.annotations.0.heritage_object.id', $stupa->id)
            ->assertJsonMissingPath('data.annotations.0.geometry_shape');

        $this->getJson("/api/v1/heritage-objects/{$stupa->id}/learning-material")
            ->assertOk()
            ->assertJsonPath('data.heritage_object.name', 'Stupa')
            ->assertJsonPath('data.geometry_mappings.0.semantics', 'didekati sebagai')
            ->assertJsonPath('data.geometry_mappings.0.geometry_shape.name', 'Setengah Bola')
            ->assertJsonPath(
                'data.geometry_mappings.0.learning_objectives.0.title',
                'Mengenali unsur setengah bola',
            )
            ->assertJsonStructure([
                'data' => [
                    'heritage_object' => ['id', 'name', 'slug', 'description'],
                    'geometry_mappings' => [[
                        'semantics',
                        'geometry_shape' => ['id', 'name', 'slug', 'description'],
                        'learning_objectives' => [['id', 'title', 'content', 'position']],
                    ]],
                ],
            ]);
    }

    public function test_material_views_require_authentication_and_exploration_phase(): void
    {
        $stupa = HeritageObject::query()->where('slug', 'stupa')->sole();

        $this->postJson("/api/v1/learning-sessions/1/materials/{$stupa->id}/viewed")
            ->assertUnauthorized();

        [$user, $session, $writeToken] = $this->createLearningSession('material', 'phase');
        Sanctum::actingAs($user);

        $this->recordView($session, $stupa, $writeToken)
            ->assertConflict();

        $session->transitionTo(LearningSessionPhase::Exploration);

        $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/materials/{$stupa->id}/viewed",
            ['viewed_at' => now()->subDay()->toISOString()],
            ['X-Session-Write-Token' => $writeToken],
        )
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.viewed_at.0', 'The viewed at field is prohibited.');

        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_material_views_require_session_ownership_and_write_token(): void
    {
        [$studentA, $sessionA, $writeTokenA] = $this->createLearningSession('material', 'owner-a');
        [$studentB] = $this->createLearningSession('material', 'owner-b');
        $stupa = HeritageObject::query()->where('slug', 'stupa')->sole();
        $sessionA->transitionTo(LearningSessionPhase::Exploration);

        Sanctum::actingAs($studentB);
        $this->recordView($sessionA, $stupa, $writeTokenA)
            ->assertNotFound();

        Sanctum::actingAs($studentA);
        $this->recordView($sessionA, $stupa, 'invalid')
            ->assertForbidden();

        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_material_views_create_attributed_activity_events(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('material', 'success');
        $stupa = HeritageObject::query()->where('slug', 'stupa')->sole();
        $session->transitionTo(LearningSessionPhase::Exploration);
        Sanctum::actingAs($user);

        $this->recordView($session, $stupa, $writeToken)
            ->assertCreated()
            ->assertJsonPath('data.type', ActivityEvent::MATERIAL_VIEWED)
            ->assertJsonPath('data.heritage_object.id', $stupa->id)
            ->assertJsonStructure(['data' => ['id', 'viewed_at']]);

        $this->assertDatabaseHas('activity_events', [
            'learning_session_id' => $session->id,
            'type' => ActivityEvent::MATERIAL_VIEWED,
            'panorama_node_id' => null,
            'heritage_object_id' => $stupa->id,
        ]);
    }

    private function recordView(
        LearningSession $session,
        HeritageObject $heritageObject,
        string $writeToken,
    ): TestResponse {
        return $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/materials/{$heritageObject->id}/viewed",
            [],
            ['X-Session-Write-Token' => $writeToken],
        );
    }
}
