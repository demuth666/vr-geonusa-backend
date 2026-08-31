<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\ManageLearningExperienceRevision;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\ActivityEvent;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Learning\Models\Quiz;
use App\Domain\Learning\Models\QuizAttempt;
use App\Domain\Research\Models\ResearchParticipant;
use App\Domain\Research\Models\ResearchStudy;
use App\Domain\School\Models\School;
use Database\Seeders\BorobudurSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExplorationCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);
    }

    public function test_new_sessions_are_pinned_to_the_latest_published_revision(): void
    {
        [$user, $participant] = $this->createParticipant('pinned');
        $firstNode = PanoramaNode::query()->firstOrFail();
        $secondNode = PanoramaNode::query()->skip(1)->firstOrFail();
        $first = $this->publishRevision($participant->researchStudy, $firstNode);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/learning-sessions', [
            'respondent_code' => $participant->respondent_code,
        ])->assertCreated();

        $this->assertSame($first->id, LearningSession::query()->sole()->learning_experience_revision_id);
        $second = $this->publishRevision($participant->researchStudy, $secondNode);

        $session = LearningSession::query()->sole();
        $this->getJson("/api/v1/learning-sessions/{$session->id}/progress")
            ->assertOk()
            ->assertJsonPath('data.panorama_visits.0.panorama_node_id', $firstNode->id)
            ->assertJsonCount(1, 'data.panorama_visits');
        $session->transitionTo(LearningSessionPhase::Exploration);
        $session->transitionTo(LearningSessionPhase::Posttest);
        $session->transitionTo(LearningSessionPhase::SelfEfficacy);
        $session->transitionTo(LearningSessionPhase::Completed);

        $this->postJson('/api/v1/learning-sessions', [
            'respondent_code' => $participant->respondent_code,
        ])->assertCreated();

        $this->assertSame($second->id, LearningSession::query()->latest('id')->firstOrFail()->learning_experience_revision_id);
    }

    public function test_progress_and_completion_require_authentication(): void
    {
        $this->getJson('/api/v1/learning-sessions/1/progress')->assertUnauthorized();
        $this->postJson('/api/v1/learning-sessions/1/exploration/complete')->assertUnauthorized();
    }

    public function test_progress_reports_only_required_activity_completion_for_the_owned_session(): void
    {
        [$user, $session, $writeToken, $node, $object, $quiz] = $this->explorationSession('progress');
        Sanctum::actingAs($user);

        ActivityEvent::create([
            'learning_session_id' => $session->id,
            'type' => ActivityEvent::PANORAMA_VISITED,
            'panorama_node_id' => $node->id,
            'occurred_at' => now(),
        ]);
        QuizAttempt::forceCreate([
            'learning_session_id' => $session->id,
            'quiz_id' => $quiz->id,
            'status' => 'submitted',
            'score' => 100,
            'submitted_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/learning-sessions/{$session->id}/progress")
            ->assertOk()
            ->assertJsonPath('data.panorama_visits.0.panorama_node_id', $node->id)
            ->assertJsonPath('data.panorama_visits.0.completed', true)
            ->assertJsonPath('data.learning_materials.0.heritage_object_id', $object->id)
            ->assertJsonPath('data.learning_materials.0.completed', false)
            ->assertJsonPath('data.micro_quizzes.0.quiz_id', $quiz->id)
            ->assertJsonPath('data.micro_quizzes.0.completed', true)
            ->assertJsonPath('data.completed', false);

        $this->assertStringNotContainsString('is_correct', $response->getContent());
        $this->assertStringNotContainsString('selected_option', $response->getContent());
        $this->assertStringNotContainsString('score', $response->getContent());

        [$otherUser, $otherSession] = $this->explorationSession('other');
        Sanctum::actingAs($otherUser);
        $this->getJson("/api/v1/learning-sessions/{$session->id}/progress")->assertNotFound();
    }

    public function test_completion_requires_every_requirement_and_the_session_write_guards(): void
    {
        [$user, $session, $writeToken, $node, $object, $quiz] = $this->explorationSession('complete');
        Sanctum::actingAs($user);

        $this->complete($session, $writeToken)->assertConflict();
        $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/exploration/complete",
            ['phase' => 'posttest'],
            ['X-Session-Write-Token' => $writeToken],
        )->assertUnprocessable();
        $this->complete($session, 'invalid')->assertForbidden();

        [$otherUser] = $this->explorationSession('complete-other');
        Sanctum::actingAs($otherUser);
        $this->complete($session, $writeToken)->assertNotFound();
        Sanctum::actingAs($user);

        ActivityEvent::create([
            'learning_session_id' => $session->id,
            'type' => ActivityEvent::PANORAMA_VISITED,
            'panorama_node_id' => $node->id,
            'occurred_at' => now(),
        ]);
        ActivityEvent::create([
            'learning_session_id' => $session->id,
            'type' => ActivityEvent::MATERIAL_VIEWED,
            'heritage_object_id' => $object->id,
            'occurred_at' => now(),
        ]);
        QuizAttempt::forceCreate([
            'learning_session_id' => $session->id,
            'quiz_id' => $quiz->id,
            'status' => 'submitted',
            'score' => 0,
            'submitted_at' => now(),
        ]);

        $this->complete($session, $writeToken)
            ->assertOk()
            ->assertJsonPath('data.phase', 'posttest');
        $this->complete($session, $writeToken)->assertConflict();
    }

    /** @return array{User, LearningSession, string, PanoramaNode, HeritageObject, Quiz} */
    private function explorationSession(string $suffix): array
    {
        [$user, $participant] = $this->createParticipant($suffix);
        $revision = $this->publishRevision($participant->researchStudy);
        $writeToken = str_pad($suffix, 64, 'x');
        $session = LearningSession::create([
            'research_participant_id' => $participant->id,
            'learning_experience_revision_id' => $revision->id,
            'write_token_hash' => hash('sha256', $writeToken),
        ]);
        $session->transitionTo(LearningSessionPhase::Exploration);

        return [
            $user,
            $session,
            $writeToken,
            PanoramaNode::query()->firstOrFail(),
            HeritageObject::query()->sole(),
            Quiz::query()->sole(),
        ];
    }

    private function publishRevision(ResearchStudy $study, ?PanoramaNode $node = null)
    {
        return app(ManageLearningExperienceRevision::class)->createAndPublish(
            $study->id,
            [($node ?? PanoramaNode::query()->firstOrFail())->id],
            [HeritageObject::query()->sole()->id],
            [Quiz::query()->sole()->id],
        );
    }

    /** @return array{User, ResearchParticipant} */
    private function createParticipant(string $suffix): array
    {
        $school = School::firstOrCreate(['name' => 'SMP GeoNusa']);
        $user = User::create([
            'email' => "exploration-{$suffix}@example.test",
            'password' => 'password',
        ]);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'name' => "Student {$suffix}",
            'student_number' => "VII-{$suffix}",
        ]);
        $study = ResearchStudy::create(['name' => "Study {$suffix}"]);

        return [$user, ResearchParticipant::create([
            'research_study_id' => $study->id,
            'student_profile_id' => $profile->id,
        ])];
    }

    private function complete(LearningSession $session, string $writeToken)
    {
        return $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/exploration/complete",
            [],
            ['X-Session-Write-Token' => $writeToken],
        );
    }
}
