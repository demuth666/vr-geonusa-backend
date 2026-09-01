<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Research\Enums\AssessmentType;
use App\Domain\Research\Models\AssessmentAttempt;
use App\Domain\Research\Models\AssessmentInstrument;
use Database\Seeders\AssessmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompletedSessionResultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AssessmentSeeder::class);
    }

    public function test_owner_can_read_a_safe_completed_session_result(): void
    {
        [$user, $session] = $this->completedSession('result', 'owner');

        $this->getJson("/api/v1/learning-sessions/{$session->id}/result")->assertUnauthorized();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/learning-sessions/{$session->id}/result")
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.phase', 'completed')
            ->assertJsonPath('data.pretest_score', 50)
            ->assertJsonPath('data.posttest_score', 100)
            ->assertJsonPath('data.activity_summary.panoramas_visited', 0)
            ->assertJsonPath('data.activity_summary.materials_viewed', 0)
            ->assertJsonPath('data.activity_summary.micro_quizzes_completed', 0);

        $this->assertStringNotContainsString('is_correct', $response->getContent());
        $this->assertStringNotContainsString('selected_option', $response->getContent());
        $this->assertStringNotContainsString('self_efficacy', $response->getContent());
        $this->assertStringNotContainsString('comparison', $response->getContent());
    }

    public function test_result_rejects_non_owners_and_incomplete_sessions(): void
    {
        [$owner, $completed] = $this->completedSession('result', 'owner');
        [$otherUser, $incomplete] = $this->createLearningSession('result', 'other');

        Sanctum::actingAs($otherUser);
        $this->getJson("/api/v1/learning-sessions/{$completed->id}/result")->assertNotFound();
        $this->getJson("/api/v1/learning-sessions/{$incomplete->id}/result")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/learning-sessions/{$incomplete->id}/result")->assertNotFound();
    }

    /** @return array{User, LearningSession} */
    private function completedSession(string $prefix, string $suffix): array
    {
        [$user, $session] = $this->createLearningSession($prefix, $suffix);

        foreach (LearningSessionPhase::cases() as $phase) {
            if ($phase !== LearningSessionPhase::Pretest) {
                $session->transitionTo($phase);
            }
        }

        foreach ([
            [AssessmentType::Pretest, 50],
            [AssessmentType::Posttest, 100],
            [AssessmentType::SelfEfficacy, null],
        ] as [$type, $score]) {
            $attempt = AssessmentAttempt::create([
                'learning_session_id' => $session->id,
                'assessment_instrument_id' => AssessmentInstrument::query()
                    ->where('type', $type)
                    ->sole()
                    ->id,
            ]);

            $attempt->forceFill([
                'status' => 'submitted',
                'score' => $score,
                'submitted_at' => now(),
            ])->save();
        }

        return [$user, $session];
    }
}
