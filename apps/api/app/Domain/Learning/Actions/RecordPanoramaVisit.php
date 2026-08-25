<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\ActivityEvent;
use App\Domain\Learning\Models\LearningSession;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RecordPanoramaVisit
{
    public function handle(
        User $user,
        int $sessionId,
        int $panoramaNodeId,
        ?string $writeToken,
    ): ActivityEvent {
        return DB::transaction(function () use ($user, $sessionId, $panoramaNodeId, $writeToken) {
            $session = LearningSession::query()
                ->ownedBy($user)
                ->lockForUpdate()
                ->findOrFail($sessionId);

            if (! $session->hasValidWriteToken($writeToken)) {
                throw new AccessDeniedHttpException;
            }

            if ($session->phase !== LearningSessionPhase::Exploration) {
                throw new ConflictHttpException('Panorama visits are only allowed during exploration.');
            }

            $node = PanoramaNode::query()->findOrFail($panoramaNodeId);
            $event = ActivityEvent::create([
                'learning_session_id' => $session->id,
                'type' => ActivityEvent::PANORAMA_VISITED,
                'panorama_node_id' => $node->id,
                'occurred_at' => now(),
            ]);

            $session->forceFill(['current_panorama_node_id' => $node->id])->save();

            return $event->setRelation('panoramaNode', $node);
        });
    }
}
