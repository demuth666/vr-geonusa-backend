<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Learning\Models\Question;
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

            return QuizAttempt::create([
                'learning_session_id' => $session->id,
                'quiz_id' => $quiz->id,
            ]);
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

            $question = Question::query()
                ->where('quiz_id', $attempt->quiz_id)
                ->find($questionId);

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

    private function assertWritableSession(LearningSession $session, ?string $writeToken): void
    {
        if (! $session->hasValidWriteToken($writeToken)) {
            throw new AccessDeniedHttpException;
        }

        if ($session->phase !== LearningSessionPhase::Exploration) {
            throw new ConflictHttpException('Micro Quizzes are only available during exploration.');
        }
    }
}
