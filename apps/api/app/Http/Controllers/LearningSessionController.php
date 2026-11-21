<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\CalculateExplorationProgress;
use App\Domain\Learning\Actions\CompleteExploration;
use App\Domain\Learning\Actions\CreateLearningSession;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
use App\Http\Requests\CompleteExplorationRequest;
use App\Http\Requests\CreateLearningSessionRequest;
use App\Http\Resources\CompletedSessionResultResource;
use App\Http\Resources\LearningSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearningSessionController extends Controller
{
    public function store(
        CreateLearningSessionRequest $request,
        CreateLearningSession $createLearningSession,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $created = $createLearningSession->handle(
            $user,
            $request->string('respondent_code')->toString(),
        );

        return response()->json([
            'data' => [
                ...LearningSessionResource::make(
                    $created['session']->load(['researchParticipant', 'currentPanoramaNode']),
                )->resolve($request),
                'write_token' => $created['write_token'],
            ],
        ], 201);
    }

    public function active(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = LearningSession::query()
            ->ownedBy($user)
            ->active()
            ->with(['researchParticipant', 'currentPanoramaNode'])
            ->first();

        if (! $session) {
            return response()->json(['data' => null]);
        }

        // The plaintext token is only ever held by the client. Resuming the
        // session on an authenticated request rotates it so a refreshed tab
        // can continue mutating without persisting the previous token.
        $writeToken = $session->reissueWriteToken();

        return response()->json([
            'data' => [
                ...LearningSessionResource::make($session)->resolve($request),
                'write_token' => $writeToken,
            ],
        ]);
    }

    public function show(Request $request, int $id): LearningSessionResource
    {
        /** @var User $user */
        $user = $request->user();
        // Reading an owned session does not require the write token. The
        // token guards mutations only; ownership is enforced here.
        $session = LearningSession::query()
            ->ownedBy($user)
            ->with(['researchParticipant', 'currentPanoramaNode'])
            ->findOrFail($id);

        return LearningSessionResource::make($session);
    }

    public function result(Request $request, int $id): CompletedSessionResultResource
    {
        /** @var User $user */
        $user = $request->user();
        $session = LearningSession::query()
            ->ownedBy($user)
            ->where('phase', LearningSessionPhase::Completed->value)
            ->with(['assessmentAttempts.instrument', 'activityEvents', 'quizAttempts'])
            ->findOrFail($id);

        return CompletedSessionResultResource::make($session);
    }

    public function progress(
        Request $request,
        CalculateExplorationProgress $calculateProgress,
        int $id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $session = LearningSession::query()
            ->ownedBy($user)
            ->with('learningExperienceRevision')
            ->findOrFail($id);

        return response()->json([
            'data' => $calculateProgress->handle($session),
        ]);
    }

    public function completeExploration(
        CompleteExplorationRequest $request,
        CompleteExploration $completeExploration,
        int $id,
    ): LearningSessionResource {
        /** @var User $user */
        $user = $request->user();
        $session = $completeExploration->handle(
            $user,
            $id,
            $request->header('X-Session-Write-Token'),
        );

        return LearningSessionResource::make(
            $session->load(['researchParticipant', 'currentPanoramaNode']),
        );
    }
}
