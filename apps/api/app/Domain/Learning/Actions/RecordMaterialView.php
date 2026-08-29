<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\ActivityEvent;
use App\Domain\Learning\Models\LearningSession;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RecordMaterialView
{
    public function handle(
        User $user,
        int $sessionId,
        int $heritageObjectId,
        ?string $writeToken,
    ): ActivityEvent {
        return DB::transaction(function () use ($user, $sessionId, $heritageObjectId, $writeToken) {
            $session = LearningSession::query()
                ->ownedBy($user)
                ->lockForUpdate()
                ->findOrFail($sessionId);

            if (! $session->hasValidWriteToken($writeToken)) {
                throw new AccessDeniedHttpException;
            }

            if ($session->phase !== LearningSessionPhase::Exploration) {
                throw new ConflictHttpException('Learning materials are only available during exploration.');
            }

            $heritageObject = HeritageObject::query()->findOrFail($heritageObjectId);
            $event = ActivityEvent::create([
                'learning_session_id' => $session->id,
                'type' => ActivityEvent::MATERIAL_VIEWED,
                'heritage_object_id' => $heritageObject->id,
                'occurred_at' => now(),
            ]);

            return $event->setRelation('heritageObject', $heritageObject);
        });
    }
}
