<?php

namespace Tests\Feature;

use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionOption;
use App\Domain\Learning\Models\Quiz;
use App\Domain\Learning\Models\QuizAnswer;
use Database\Seeders\BorobudurSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MicroQuizAttemptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);
    }

    public function test_micro_quiz_attempt_mutations_require_authentication(): void
    {
        $this->postJson('/api/v1/learning-sessions/1/quiz-attempts')->assertUnauthorized();
        $this->putJson('/api/v1/quiz-attempts/1/answers/1')->assertUnauthorized();
    }

    public function test_student_starts_a_micro_quiz_attempt_during_owned_exploration_session(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('quiz-attempt', 'a', exploration: true);
        $quiz = Quiz::query()->sole();
        Sanctum::actingAs($user);

        $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/quiz-attempts",
            ['quiz_id' => $quiz->id],
            ['X-Session-Write-Token' => $writeToken],
        )
            ->assertCreated()
            ->assertJsonPath('data.quiz_id', $quiz->id)
            ->assertJsonMissingPath('data.learning_session_id');
    }

    public function test_starting_an_attempt_enforces_owner_token_phase_and_server_owned_fields(): void
    {
        [$studentA, $sessionA, $tokenA] = $this->createLearningSession('quiz-guard', 'a', exploration: true);
        [$studentB, $sessionB, $tokenB] = $this->createLearningSession('quiz-guard', 'b');
        $quiz = Quiz::query()->sole();
        Sanctum::actingAs($studentA);

        $this->postJson(
            "/api/v1/learning-sessions/{$sessionA->id}/quiz-attempts",
            ['quiz_id' => $quiz->id],
            ['X-Session-Write-Token' => 'invalid'],
        )->assertForbidden();

        $this->postJson(
            "/api/v1/learning-sessions/{$sessionB->id}/quiz-attempts",
            ['quiz_id' => $quiz->id],
            ['X-Session-Write-Token' => $tokenB],
        )->assertNotFound();

        $this->postJson(
            "/api/v1/learning-sessions/{$sessionA->id}/quiz-attempts",
            ['quiz_id' => 999999],
            ['X-Session-Write-Token' => $tokenA],
        )->assertUnprocessable();

        $this->postJson(
            "/api/v1/learning-sessions/{$sessionA->id}/quiz-attempts",
            [
                'quiz_id' => $quiz->id,
                'is_correct' => true,
                'score' => 100,
                'feedback' => 'client',
                'unexpected' => 'value',
            ],
            ['X-Session-Write-Token' => $tokenA],
        )->assertUnprocessable();

        Sanctum::actingAs($studentB);

        $this->postJson(
            "/api/v1/learning-sessions/{$sessionB->id}/quiz-attempts",
            ['quiz_id' => $quiz->id],
            ['X-Session-Write-Token' => $tokenB],
        )->assertConflict();
    }

    public function test_server_scores_an_answer_and_returns_its_formative_feedback(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('quiz-answer', 'a', exploration: true);
        $quiz = Quiz::query()->sole();
        $question = Question::query()->with('options')->sole();
        $wrongOption = $question->options->firstWhere('is_correct', false);
        $correctOption = $question->options->firstWhere('is_correct', true);
        Sanctum::actingAs($user);

        $attemptId = $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/quiz-attempts",
            ['quiz_id' => $quiz->id],
            ['X-Session-Write-Token' => $writeToken],
        )->json('data.id');

        $this->putJson(
            "/api/v1/quiz-attempts/{$attemptId}/answers/{$question->id}",
            ['selected_option_id' => $wrongOption->id],
            ['X-Session-Write-Token' => $writeToken],
        )
            ->assertOk()
            ->assertJsonPath('data.question_id', $question->id)
            ->assertJsonPath('data.selected_option_id', $wrongOption->id)
            ->assertJsonPath('data.is_correct', false)
            ->assertJsonPath('data.feedback', $wrongOption->feedback);

        $answer = QuizAnswer::query()->sole();
        $this->assertFalse($answer->is_correct);
        $this->assertSame($wrongOption->id, $answer->selected_option_id);

        $correctAttemptId = $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/quiz-attempts",
            ['quiz_id' => $quiz->id],
            ['X-Session-Write-Token' => $writeToken],
        )->json('data.id');

        $this->putJson(
            "/api/v1/quiz-attempts/{$correctAttemptId}/answers/{$question->id}",
            ['selected_option_id' => $correctOption->id],
            ['X-Session-Write-Token' => $writeToken],
        )
            ->assertOk()
            ->assertJsonPath('data.is_correct', true)
            ->assertJsonPath('data.feedback', $correctOption->feedback);

        $this->assertTrue(QuizAnswer::query()->latest('id')->firstOrFail()->is_correct);
    }

    public function test_answering_enforces_owner_token_phase_membership_and_immutability(): void
    {
        [$studentA, $sessionA, $tokenA] = $this->createLearningSession('quiz-answer-guard', 'a', exploration: true);
        [$studentB, $sessionB, $tokenB] = $this->createLearningSession('quiz-answer-guard', 'b', exploration: true);
        $quiz = Quiz::query()->sole();
        $question = Question::query()->with('options')->sole();
        $option = $question->options->first();
        $otherQuestion = Question::create([
            'quiz_id' => $quiz->id,
            'prompt' => 'Pertanyaan lain',
            'position' => 2,
        ]);
        $otherOption = QuestionOption::create([
            'question_id' => $otherQuestion->id,
            'text' => 'Pilihan lain',
            'position' => 1,
            'is_correct' => false,
            'feedback' => 'Umpan balik lain.',
        ]);
        Sanctum::actingAs($studentA);

        $attemptA = $this->postJson(
            "/api/v1/learning-sessions/{$sessionA->id}/quiz-attempts",
            ['quiz_id' => $quiz->id],
            ['X-Session-Write-Token' => $tokenA],
        )->json('data.id');
        $phaseAttempt = $this->postJson(
            "/api/v1/learning-sessions/{$sessionA->id}/quiz-attempts",
            ['quiz_id' => $quiz->id],
            ['X-Session-Write-Token' => $tokenA],
        )->json('data.id');

        $this->putJson(
            "/api/v1/quiz-attempts/{$attemptA}/answers/{$question->id}",
            ['selected_option_id' => $option->id],
            ['X-Session-Write-Token' => 'invalid'],
        )->assertForbidden();

        Sanctum::actingAs($studentB);

        $this->putJson(
            "/api/v1/quiz-attempts/{$attemptA}/answers/{$question->id}",
            ['selected_option_id' => $option->id],
            ['X-Session-Write-Token' => $tokenB],
        )->assertNotFound();

        Sanctum::actingAs($studentA);

        $this->putJson(
            "/api/v1/quiz-attempts/{$attemptA}/answers/999999",
            ['selected_option_id' => $option->id],
            ['X-Session-Write-Token' => $tokenA],
        )
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.details.fields.question_id.0',
                'The question does not belong to this quiz attempt.',
            );

        $this->putJson(
            "/api/v1/quiz-attempts/{$attemptA}/answers/{$question->id}",
            ['selected_option_id' => $otherOption->id],
            ['X-Session-Write-Token' => $tokenA],
        )
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.details.fields.selected_option_id.0',
                'The selected option does not belong to this question.',
            );

        $this->putJson(
            "/api/v1/quiz-attempts/{$attemptA}/answers/{$question->id}",
            [
                'selected_option_id' => $option->id,
                'is_correct' => false,
                'score' => 0,
                'feedback' => 'client',
                'unexpected' => 'value',
            ],
            ['X-Session-Write-Token' => $tokenA],
        )->assertUnprocessable();

        $this->putJson(
            "/api/v1/quiz-attempts/{$attemptA}/answers/{$question->id}",
            ['selected_option_id' => $option->id],
            ['X-Session-Write-Token' => $tokenA],
        )->assertOk();

        $this->putJson(
            "/api/v1/quiz-attempts/{$attemptA}/answers/{$question->id}",
            ['selected_option_id' => $otherOption->id],
            ['X-Session-Write-Token' => $tokenA],
        )->assertUnprocessable();

        $this->putJson(
            "/api/v1/quiz-attempts/{$attemptA}/answers/{$question->id}",
            ['selected_option_id' => $option->id],
            ['X-Session-Write-Token' => $tokenA],
        )->assertConflict();

        $this->assertDatabaseCount('quiz_answers', 1);

        $sessionA->transitionTo(LearningSessionPhase::Posttest);

        $this->putJson(
            "/api/v1/quiz-attempts/{$phaseAttempt}/answers/{$question->id}",
            ['selected_option_id' => $option->id],
            ['X-Session-Write-Token' => $tokenA],
        )->assertConflict();
    }
}
