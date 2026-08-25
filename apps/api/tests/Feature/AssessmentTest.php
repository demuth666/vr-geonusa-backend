<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Research\Models\AssessmentAnswer;
use App\Domain\Research\Models\AssessmentAttempt;
use App\Domain\Research\Models\AssessmentItem;
use App\Domain\Research\Models\ResearchParticipant;
use App\Domain\Research\Models\ResearchStudy;
use App\Domain\School\Models\School;
use Database\Seeders\AssessmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assessment_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/learning-sessions/1/assessment-attempts')->assertUnauthorized();
        $this->getJson('/api/v1/assessment-attempts/1')->assertUnauthorized();
        $this->putJson('/api/v1/assessment-attempts/1/answers/1')->assertUnauthorized();
        $this->postJson('/api/v1/assessment-attempts/1/submit')->assertUnauthorized();
    }

    public function test_student_starts_and_reads_owned_pretest_without_answer_leaks(): void
    {
        [$user, $session, $writeToken] = $this->createSession('a');
        Sanctum::actingAs($user);

        $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/assessment-attempts",
            [],
            ['X-Session-Write-Token' => 'invalid'],
        )->assertForbidden();

        $created = $this->startAttempt($session, $writeToken)
            ->assertCreated()
            ->assertJsonPath('data.type', 'pretest')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items.0.selected_option_id', null)
            ->assertJsonCount(2, 'data.items');

        $this->assertStringNotContainsString('is_correct', $created->getContent());
        $this->assertStringNotContainsString('score', $created->getContent());

        $attemptId = $created->json('data.id');

        $this->getJson("/api/v1/assessment-attempts/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('data.id', $attemptId);

        [$otherUser] = $this->createSession('b');
        Sanctum::actingAs($otherUser);

        $this->getJson("/api/v1/assessment-attempts/{$attemptId}")->assertNotFound();
        $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/assessment-attempts",
            [],
            ['X-Session-Write-Token' => $writeToken],
        )->assertNotFound();
    }

    public function test_pretest_is_scored_by_server_then_locked_and_advances_session(): void
    {
        [$user, $session, $writeToken] = $this->createSession('a');
        Sanctum::actingAs($user);

        $attemptId = $this->startAttempt($session, $writeToken)->json('data.id');
        $items = AssessmentItem::query()->with('options')->orderBy('position')->get();
        $correctOption = $items[0]->options->firstWhere('is_correct', true);
        $wrongOption = $items[1]->options->firstWhere('is_correct', false);

        $this->putJson(
            "/api/v1/assessment-attempts/{$attemptId}/answers/{$items[0]->id}",
            ['selected_option_id' => $correctOption->id],
            ['X-Session-Write-Token' => $writeToken],
        )
            ->assertOk()
            ->assertJsonPath('data.items.0.selected_option_id', $correctOption->id);

        $this->putJson(
            "/api/v1/assessment-attempts/{$attemptId}/answers/{$items[1]->id}",
            ['selected_option_id' => $wrongOption->id],
            ['X-Session-Write-Token' => $writeToken],
        )->assertOk();

        $this->postJson(
            "/api/v1/assessment-attempts/{$attemptId}/submit",
            ['score' => 100],
            ['X-Session-Write-Token' => $writeToken],
        )
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.score.0', 'The score field is prohibited.');

        $submitted = $this->postJson(
            "/api/v1/assessment-attempts/{$attemptId}/submit",
            [],
            ['X-Session-Write-Token' => $writeToken],
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.type', 'pretest');

        $this->assertStringNotContainsString('is_correct', $submitted->getContent());
        $this->assertStringNotContainsString('score', $submitted->getContent());
        $this->assertSame(50, AssessmentAttempt::query()->findOrFail($attemptId)->score);
        $this->assertSame(
            [true, false],
            AssessmentAnswer::query()->orderBy('assessment_item_id')->pluck('is_correct')->all(),
        );
        $this->assertSame(LearningSessionPhase::Exploration, $session->fresh()->phase);

        $this->putJson(
            "/api/v1/assessment-attempts/{$attemptId}/answers/{$items[0]->id}",
            ['selected_option_id' => $correctOption->id],
            ['X-Session-Write-Token' => $writeToken],
        )->assertConflict();

        $this->postJson(
            "/api/v1/assessment-attempts/{$attemptId}/submit",
            [],
            ['X-Session-Write-Token' => $writeToken],
        )->assertConflict();
    }

    public function test_attempt_rejects_invalid_options_missing_answers_and_wrong_phase(): void
    {
        [$user, $session, $writeToken] = $this->createSession('a');
        Sanctum::actingAs($user);

        $attemptId = $this->startAttempt($session, $writeToken)->json('data.id');
        $items = AssessmentItem::query()->with('options')->orderBy('position')->get();

        $this->putJson(
            "/api/v1/assessment-attempts/{$attemptId}/answers/{$items[0]->id}",
            ['selected_option_id' => $items[0]->options->first()->id],
            ['X-Session-Write-Token' => 'invalid'],
        )->assertForbidden();

        $this->postJson(
            "/api/v1/assessment-attempts/{$attemptId}/submit",
            [],
            ['X-Session-Write-Token' => 'invalid'],
        )->assertForbidden();

        $this->putJson(
            "/api/v1/assessment-attempts/{$attemptId}/answers/{$items[0]->id}",
            ['selected_option_id' => $items[1]->options->first()->id],
            ['X-Session-Write-Token' => $writeToken],
        )
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.details.fields.selected_option_id.0',
                'The selected option does not belong to this item.',
            );

        $this->postJson(
            "/api/v1/assessment-attempts/{$attemptId}/submit",
            [],
            ['X-Session-Write-Token' => $writeToken],
        )->assertConflict();

        $this->assertSame(LearningSessionPhase::Pretest, $session->fresh()->phase);
        $this->assertSame('draft', AssessmentAttempt::query()->findOrFail($attemptId)->status);

        [$otherUser, $otherSession, $otherToken] = $this->createSession('b');
        $otherSession->transitionTo(LearningSessionPhase::Exploration);
        Sanctum::actingAs($otherUser);

        $this->startAttempt($otherSession, $otherToken)->assertConflict();
    }

    public function test_development_assessment_seeder_is_idempotent(): void
    {
        $this->seed(AssessmentSeeder::class);
        $this->seed(AssessmentSeeder::class);

        $this->assertDatabaseCount('assessment_instruments', 1);
        $this->assertDatabaseCount('assessment_items', 2);
        $this->assertDatabaseCount('assessment_options', 6);
    }

    /** @return array{User, LearningSession, string} */
    private function createSession(string $suffix): array
    {
        $this->seed(AssessmentSeeder::class);

        $school = School::firstOrCreate(['name' => 'SMP GeoNusa']);
        $user = User::create([
            'email' => "assessment-{$suffix}@example.test",
            'password' => 'password',
        ]);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'name' => "Assessment Student {$suffix}",
            'student_number' => "ASSESS-{$suffix}",
        ]);
        $participant = ResearchParticipant::create([
            'research_study_id' => ResearchStudy::query()->sole()->id,
            'student_profile_id' => $profile->id,
        ]);
        $writeToken = str_repeat($suffix, 64);
        $session = LearningSession::create([
            'research_participant_id' => $participant->id,
            'write_token_hash' => hash('sha256', $writeToken),
        ]);

        return [$user, $session, $writeToken];
    }

    private function startAttempt(LearningSession $session, string $writeToken)
    {
        return $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/assessment-attempts",
            [],
            ['X-Session-Write-Token' => $writeToken],
        );
    }
}
