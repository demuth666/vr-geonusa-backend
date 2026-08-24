<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Research\Models\ResearchParticipant;
use App\Domain\Research\Models\ResearchStudy;
use App\Domain\School\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class LearningSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_learning_session_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/learning-sessions')->assertUnauthorized();
        $this->getJson('/api/v1/me/learning-sessions/active')->assertUnauthorized();
        $this->getJson('/api/v1/learning-sessions/1')->assertUnauthorized();
    }

    public function test_student_creates_one_pretest_session_and_can_resume_it(): void
    {
        [$user, $participant] = $this->createParticipant('a');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/learning-sessions', [
            'respondent_code' => $participant->respondent_code,
        ])
            ->assertCreated()
            ->assertJsonPath('data.phase', 'pretest')
            ->assertJsonPath('data.respondent_code', $participant->respondent_code)
            ->assertJsonStructure(['data' => ['id', 'write_token', 'created_at', 'updated_at']])
            ->assertJsonMissingPath('data.write_token_hash');

        $session = LearningSession::query()->firstOrFail();
        $writeToken = $response->json('data.write_token');

        $this->assertSame(hash('sha256', $writeToken), $session->write_token_hash);
        $this->assertNotSame($writeToken, $session->write_token_hash);

        $this->getJson('/api/v1/me/learning-sessions/active')
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.phase', 'pretest')
            ->assertJsonMissingPath('data.write_token')
            ->assertJsonMissingPath('data.write_token_hash');

        $this->postJson('/api/v1/learning-sessions', [
            'respondent_code' => $participant->respondent_code,
        ])->assertConflict();

        $this->assertDatabaseCount('learning_sessions', 1);
    }

    public function test_client_cannot_set_session_phase(): void
    {
        [$user, $participant] = $this->createParticipant('a');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/learning-sessions', [
            'respondent_code' => $participant->respondent_code,
            'phase' => 'completed',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.phase.0', 'The phase field is prohibited.');

        $this->assertDatabaseCount('learning_sessions', 0);
    }

    public function test_session_phase_only_advances_one_step(): void
    {
        [$user, $participant] = $this->createParticipant('a');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/learning-sessions', [
            'respondent_code' => $participant->respondent_code,
        ])->assertCreated();

        $session = LearningSession::query()->firstOrFail();
        $session->transitionTo(LearningSessionPhase::Exploration);

        $this->assertSame(LearningSessionPhase::Exploration, $session->fresh()->phase);

        $this->expectException(LogicException::class);
        $session->transitionTo(LearningSessionPhase::Completed);
    }

    public function test_stale_session_cannot_overwrite_a_newer_phase(): void
    {
        [$user, $participant] = $this->createParticipant('a');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/learning-sessions', [
            'respondent_code' => $participant->respondent_code,
        ])->assertCreated();

        $session = LearningSession::query()->firstOrFail();
        $staleSession = $session->fresh();
        $session->transitionTo(LearningSessionPhase::Exploration);

        $this->expectException(LogicException::class);
        $staleSession->transitionTo(LearningSessionPhase::Exploration);
    }

    public function test_session_access_requires_ownership_and_valid_write_token(): void
    {
        [$studentA, $participantA] = $this->createParticipant('a');
        [$studentB] = $this->createParticipant('b');
        Sanctum::actingAs($studentA);

        $created = $this->postJson('/api/v1/learning-sessions', [
            'respondent_code' => $participantA->respondent_code,
        ])->assertCreated();

        $sessionId = $created->json('data.id');
        $writeToken = $created->json('data.write_token');

        Sanctum::actingAs($studentB);

        $this->withHeader('X-Session-Write-Token', $writeToken)
            ->getJson("/api/v1/learning-sessions/{$sessionId}")
            ->assertNotFound();

        $this->postJson('/api/v1/learning-sessions', [
            'respondent_code' => $participantA->respondent_code,
        ])->assertNotFound();

        Sanctum::actingAs($studentA);

        $this->withHeader('X-Session-Write-Token', 'invalid-token')
            ->getJson("/api/v1/learning-sessions/{$sessionId}")
            ->assertForbidden();

        $this->withHeader('X-Session-Write-Token', $writeToken)
            ->getJson("/api/v1/learning-sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonMissingPath('data.write_token')
            ->assertJsonMissingPath('data.write_token_hash');
    }

    /** @return array{User, ResearchParticipant} */
    private function createParticipant(string $suffix): array
    {
        $school = School::firstOrCreate(['name' => 'SMP GeoNusa']);
        $user = User::create([
            'email' => "student-{$suffix}@example.test",
            'password' => 'password',
        ]);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'name' => "Student {$suffix}",
            'student_number' => "VII-{$suffix}",
        ]);
        $study = ResearchStudy::firstOrCreate([
            'name' => 'VR GeoNusa Borobudur Study',
        ]);
        $participant = ResearchParticipant::create([
            'research_study_id' => $study->id,
            'student_profile_id' => $profile->id,
        ]);

        return [$user, $participant];
    }
}
