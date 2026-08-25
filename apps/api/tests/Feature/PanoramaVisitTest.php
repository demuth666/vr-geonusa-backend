<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\ActivityEvent;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Research\Models\ResearchParticipant;
use App\Domain\Research\Models\ResearchStudy;
use App\Domain\School\Models\School;
use Database\Seeders\BorobudurSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PanoramaVisitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);
    }

    public function test_panorama_visits_require_authentication(): void
    {
        $this->postJson('/api/v1/learning-sessions/1/panorama-visits')
            ->assertUnauthorized();
    }

    public function test_only_exploration_sessions_can_record_valid_visits(): void
    {
        [$user, $session, $writeToken] = $this->createSession('a');
        $node = PanoramaNode::query()->firstOrFail();
        Sanctum::actingAs($user);

        $this->recordVisit($session, $node, $writeToken)
            ->assertConflict();

        $this->assertDatabaseCount('activity_events', 0);
        $this->assertNull($session->fresh()->current_panorama_node_id);

        $session->transitionTo(LearningSessionPhase::Exploration);

        $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/panorama-visits",
            ['panorama_node_id' => 999999],
            ['X-Session-Write-Token' => $writeToken],
        )
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.details.fields.panorama_node_id.0',
                'The selected panorama node id is invalid.',
            );

        $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/panorama-visits",
            [
                'panorama_node_id' => $node->id,
                'visited_at' => now()->subDay()->toISOString(),
            ],
            ['X-Session-Write-Token' => $writeToken],
        )
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.visited_at.0', 'The visited at field is prohibited.');
    }

    public function test_visit_requires_session_ownership_and_write_token(): void
    {
        [$studentA, $sessionA, $writeTokenA] = $this->createSession('a', exploration: true);
        [$studentB] = $this->createSession('b', exploration: true);
        $node = PanoramaNode::query()->firstOrFail();

        Sanctum::actingAs($studentB);
        $this->recordVisit($sessionA, $node, $writeTokenA)
            ->assertNotFound();

        Sanctum::actingAs($studentA);
        $this->recordVisit($sessionA, $node, 'invalid')
            ->assertForbidden();

        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_visits_create_events_update_current_node_and_resume_latest_node(): void
    {
        [$user, $session, $writeToken] = $this->createSession('a', exploration: true);
        $east = PanoramaNode::query()->where('slug', 'pelataran-timur')->firstOrFail();
        $stupa = PanoramaNode::query()->where('slug', 'stupa-induk')->firstOrFail();
        Sanctum::actingAs($user);

        $this->recordVisit($session, $east, $writeToken)
            ->assertCreated()
            ->assertJsonPath('data.type', ActivityEvent::PANORAMA_VISITED)
            ->assertJsonPath('data.panorama_node.id', $east->id)
            ->assertJsonStructure(['data' => ['id', 'visited_at']]);

        $this->getJson('/api/v1/me/learning-sessions/active')
            ->assertOk()
            ->assertJsonPath('data.current_panorama_node.id', $east->id)
            ->assertJsonPath('data.current_panorama_node.slug', 'pelataran-timur');

        $this->recordVisit($session, $stupa, $writeToken)
            ->assertCreated()
            ->assertJsonPath('data.panorama_node.id', $stupa->id);

        $this->assertDatabaseCount('activity_events', 2);
        $this->assertDatabaseHas('activity_events', [
            'learning_session_id' => $session->id,
            'type' => ActivityEvent::PANORAMA_VISITED,
            'panorama_node_id' => $east->id,
        ]);
        $this->assertSame($stupa->id, $session->fresh()->current_panorama_node_id);

        $this->getJson('/api/v1/me/learning-sessions/active')
            ->assertOk()
            ->assertJsonPath('data.current_panorama_node.id', $stupa->id);
    }

    /** @return array{User, LearningSession, string} */
    private function createSession(string $suffix, bool $exploration = false): array
    {
        $school = School::firstOrCreate(['name' => 'SMP GeoNusa']);
        $user = User::create([
            'email' => "panorama-{$suffix}@example.test",
            'password' => 'password',
        ]);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'name' => "Panorama Student {$suffix}",
            'student_number' => "PANORAMA-{$suffix}",
        ]);
        $study = ResearchStudy::firstOrCreate([
            'name' => 'VR GeoNusa Borobudur Study',
        ]);
        $participant = ResearchParticipant::create([
            'research_study_id' => $study->id,
            'student_profile_id' => $profile->id,
        ]);
        $writeToken = str_repeat($suffix, 64);
        $session = LearningSession::create([
            'research_participant_id' => $participant->id,
            'write_token_hash' => hash('sha256', $writeToken),
        ]);

        if ($exploration) {
            $session->transitionTo(LearningSessionPhase::Exploration);
        }

        return [$user, $session, $writeToken];
    }

    private function recordVisit(
        LearningSession $session,
        PanoramaNode $node,
        string $writeToken,
    ) {
        return $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/panorama-visits",
            ['panorama_node_id' => $node->id],
            ['X-Session-Write-Token' => $writeToken],
        );
    }
}
