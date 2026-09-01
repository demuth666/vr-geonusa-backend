<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\CreateLearningSession;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
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

        return response()->json([
            'data' => $session
                ? LearningSessionResource::make($session)->resolve($request)
                : null,
        ]);
    }

    public function show(Request $request, int $id): LearningSessionResource
    {
        /** @var User $user */
        $user = $request->user();
        $session = LearningSession::query()
            ->ownedBy($user)
            ->with(['researchParticipant', 'currentPanoramaNode'])
            ->findOrFail($id);

        abort_unless(
            $session->hasValidWriteToken($request->header('X-Session-Write-Token')),
            403,
        );

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
}
