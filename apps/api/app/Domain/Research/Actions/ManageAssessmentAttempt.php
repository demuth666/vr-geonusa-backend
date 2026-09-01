<?php

namespace App\Domain\Research\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Research\Enums\AssessmentType;
use App\Domain\Research\Models\AssessmentAnswer;
use App\Domain\Research\Models\AssessmentAttempt;
use App\Domain\Research\Models\AssessmentInstrument;
use App\Domain\Research\Models\AssessmentItem;
use App\Domain\Research\Models\AssessmentOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ManageAssessmentAttempt
{
    public function start(User $user, int $sessionId, ?string $writeToken): AssessmentAttempt
    {
        return DB::transaction(function () use ($user, $sessionId, $writeToken) {
            $session = LearningSession::query()
                ->ownedBy($user)
                ->with('researchParticipant')
                ->lockForUpdate()
                ->findOrFail($sessionId);

            $this->assertValidWriteToken($session, $writeToken);

            $type = AssessmentType::tryFrom($session->phase->value);

            if (! $type) {
                throw new ConflictHttpException('The session is not in an assessment phase.');
            }

            $instrument = AssessmentInstrument::query()
                ->where('research_study_id', $session->researchParticipant->research_study_id)
                ->where('type', $type->value)
                ->first();

            if (! $instrument) {
                throw new ConflictHttpException('No assessment instrument is configured for this phase.');
            }

            if (AssessmentAttempt::query()
                ->where('learning_session_id', $session->id)
                ->where('assessment_instrument_id', $instrument->id)
                ->exists()) {
                throw new ConflictHttpException('An assessment attempt already exists for this phase.');
            }

            $attempt = AssessmentAttempt::create([
                'learning_session_id' => $session->id,
                'assessment_instrument_id' => $instrument->id,
            ]);

            return $this->load($attempt);
        });
    }

    public function findOwned(User $user, int $attemptId): AssessmentAttempt
    {
        $attempt = AssessmentAttempt::query()
            ->ownedBy($user)
            ->findOrFail($attemptId);

        return $this->load($attempt);
    }

    public function saveAnswer(
        User $user,
        int $attemptId,
        int $itemId,
        int $selectedOptionId,
        ?string $writeToken,
    ): AssessmentAttempt {
        return DB::transaction(function () use ($user, $attemptId, $itemId, $selectedOptionId, $writeToken) {
            $attempt = $this->lockOwned($user, $attemptId);
            $this->assertWritable($attempt, $writeToken);

            $item = AssessmentItem::query()
                ->where('assessment_instrument_id', $attempt->assessment_instrument_id)
                ->find($itemId);

            if (! $item) {
                throw ValidationException::withMessages([
                    'item_id' => ['The item does not belong to this assessment attempt.'],
                ]);
            }

            $option = AssessmentOption::query()
                ->where('assessment_item_id', $item->id)
                ->find($selectedOptionId);

            if (! $option) {
                throw ValidationException::withMessages([
                    'selected_option_id' => ['The selected option does not belong to this item.'],
                ]);
            }

            AssessmentAnswer::updateOrCreate(
                [
                    'assessment_attempt_id' => $attempt->id,
                    'assessment_item_id' => $item->id,
                ],
                [
                    'selected_option_id' => $option->id,
                    'is_correct' => null,
                ],
            );

            return $this->load($attempt);
        });
    }

    public function submit(User $user, int $attemptId, ?string $writeToken): AssessmentAttempt
    {
        return DB::transaction(function () use ($user, $attemptId, $writeToken) {
            $attempt = $this->lockOwned($user, $attemptId);
            $this->assertWritable($attempt, $writeToken);

            $itemCount = $attempt->instrument->items()->count();
            $answers = $attempt->answers()->with('selectedOption')->get();

            if ($itemCount === 0 || $answers->count() !== $itemCount) {
                throw new ConflictHttpException('Every assessment item must be answered before submission.');
            }

            $score = null;

            if ($attempt->instrument->type !== AssessmentType::SelfEfficacy) {
                $correctCount = 0;

                foreach ($answers as $answer) {
                    $answer->is_correct = $answer->selectedOption->is_correct;
                    $answer->save();
                    $correctCount += (int) $answer->is_correct;
                }

                $score = (int) round(($correctCount / $itemCount) * 100);
            }

            $attempt->forceFill([
                'status' => 'submitted',
                'score' => $score,
                'submitted_at' => now(),
            ])->save();

            match ($attempt->instrument->type) {
                AssessmentType::Pretest => $attempt->learningSession->transitionTo(LearningSessionPhase::Exploration),
                AssessmentType::Posttest => $attempt->learningSession->transitionTo(LearningSessionPhase::SelfEfficacy),
                AssessmentType::SelfEfficacy => null,
            };

            return $this->load($attempt);
        });
    }

    private function lockOwned(User $user, int $attemptId): AssessmentAttempt
    {
        return AssessmentAttempt::query()
            ->ownedBy($user)
            ->with(['learningSession', 'instrument'])
            ->lockForUpdate()
            ->findOrFail($attemptId);
    }

    private function assertWritable(AssessmentAttempt $attempt, ?string $writeToken): void
    {
        $this->assertValidWriteToken($attempt->learningSession, $writeToken);

        if ($attempt->status !== 'draft') {
            throw new ConflictHttpException('The assessment attempt has already been submitted.');
        }

        if ($attempt->learningSession->phase->value !== $attempt->instrument->type->value) {
            throw new ConflictHttpException('The assessment attempt is not valid in the current session phase.');
        }
    }

    private function assertValidWriteToken(LearningSession $session, ?string $writeToken): void
    {
        if (! $session->hasValidWriteToken($writeToken)) {
            throw new AccessDeniedHttpException;
        }
    }

    private function load(AssessmentAttempt $attempt): AssessmentAttempt
    {
        return $attempt->load([
            'instrument.items.options',
            'answers',
        ]);
    }
}
