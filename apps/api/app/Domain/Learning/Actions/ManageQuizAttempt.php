<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Learning\Models\QuestionOption;
use App\Domain\Learning\Models\Quiz;
use App\Domain\Learning\Models\QuizAnswer;
use App\Domain\Learning\Models\QuizAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ManageQuizAttempt
{
    public function start(User $user, int $sessionId, int $quizId, ?string $writeToken): QuizAttempt
    {
        return DB::transaction(function () use ($user, $sessionId, $quizId, $writeToken) {
            $session = LearningSession::query()
                ->ownedBy($user)
                ->lockForUpdate()
                ->findOrFail($sessionId);

            $this->assertWritableSession($session, $writeToken);
            $quiz = Quiz::query()->findOrFail($quizId);

            $attempt = QuizAttempt::create([
                'learning_session_id' => $session->id,
                'quiz_id' => $quiz->id,
            ]);

            $attempt->questions()->attach($quiz->questions()->pluck('questions.id'));

            return $attempt;
        });
    }

    public function answer(
        User $user,
        int $attemptId,
        int $questionId,
        int $selectedOptionId,
        ?string $writeToken,
    ): QuizAnswer {
        return DB::transaction(function () use ($user, $attemptId, $questionId, $selectedOptionId, $writeToken) {
            $attempt = QuizAttempt::query()
                ->ownedBy($user)
                ->lockForUpdate()
                ->findOrFail($attemptId);

            $session = LearningSession::query()
                ->ownedBy($user)
                ->lockForUpdate()
                ->findOrFail($attempt->learning_session_id);

            $this->assertWritableSession($session, $writeToken);
            $this->assertDraft($attempt);

            $question = $attempt->questions()->find($questionId);

            if (! $question) {
                throw ValidationException::withMessages([
                    'question_id' => ['The question does not belong to this quiz attempt.'],
                ]);
            }

            $option = QuestionOption::query()
                ->where('question_id', $question->id)
                ->find($selectedOptionId);

            if (! $option) {
                throw ValidationException::withMessages([
                    'selected_option_id' => ['The selected option does not belong to this question.'],
                ]);
            }

            if ($attempt->answers()->where('question_id', $question->id)->exists()) {
                throw new ConflictHttpException('This question has already been answered.');
            }

            $answer = QuizAnswer::create([
                'quiz_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_option_id' => $option->id,
                'is_correct' => $option->is_correct,
            ]);

            return $answer->setRelation('selectedOption', $option);
        });
    }

    public function submit(User $user, int $attemptId, ?string $writeToken): QuizAttempt
    {
        return DB::transaction(function () use ($user, $attemptId, $writeToken) {
            $attempt = QuizAttempt::query()
                ->ownedBy($user)
                ->lockForUpdate()
                ->findOrFail($attemptId);

            $session = LearningSession::query()
                ->ownedBy($user)
                ->lockForUpdate()
                ->findOrFail($attempt->learning_session_id);

            $this->assertWritableSession($session, $writeToken);
            $this->assertDraft($attempt);

            $questionCount = $attempt->questions()->count();
            $answers = $attempt->answers()->get();

            if ($questionCount === 0 || $answers->count() !== $questionCount) {
                throw new ConflictHttpException('Every Micro Quiz question must be answered before submission.');
            }

            $correctCount = $answers->where('is_correct', true)->count();

            $attempt->forceFill([
                'status' => 'submitted',
                'score' => (int) round(($correctCount / $questionCount) * 100),
                'submitted_at' => now(),
            ])->save();

            return $attempt;
        });
    }

    private function assertWritableSession(LearningSession $session, ?string $writeToken): void
    {
        if (! $session->hasValidWriteToken($writeToken)) {
            throw new AccessDeniedHttpException;
        }

        if ($session->phase !== LearningSessionPhase::Exploration) {
            throw new ConflictHttpException('Micro Quizzes are only available during exploration.');
        }
    }

    private function assertDraft(QuizAttempt $attempt): void
    {
        if ($attempt->status !== 'draft') {
            throw new ConflictHttpException('The Micro Quiz attempt has already been submitted.');
        }
    }
}
