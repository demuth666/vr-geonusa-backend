<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CompleteExploration
{
    public function __construct(private CalculateExplorationProgress $progress) {}

    public function handle(User $user, int $sessionId, ?string $writeToken): LearningSession
    {
        return DB::transaction(function () use ($user, $sessionId, $writeToken) {
            $session = LearningSession::query()
                ->ownedBy($user)
                ->with('learningExperienceRevision')
                ->lockForUpdate()
                ->findOrFail($sessionId);

            if (! $session->hasValidWriteToken($writeToken)) {
                throw new AccessDeniedHttpException;
            }

            if ($session->phase !== LearningSessionPhase::Exploration) {
                throw new ConflictHttpException('Exploration can only be completed during exploration.');
            }

            if (! $this->progress->handle($session)['completed']) {
                throw new ConflictHttpException('Every required exploration activity must be completed.');
            }

            $session->transitionTo(LearningSessionPhase::Posttest);

            return $session;
        });
    }
}
