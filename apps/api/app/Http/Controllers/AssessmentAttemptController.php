<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Research\Actions\ManageAssessmentAttempt;
use App\Http\Requests\SaveAssessmentAnswerRequest;
use App\Http\Requests\StartAssessmentAttemptRequest;
use App\Http\Requests\SubmitAssessmentAttemptRequest;
use App\Http\Resources\AssessmentAttemptResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentAttemptController extends Controller
{
    public function store(
        StartAssessmentAttemptRequest $request,
        ManageAssessmentAttempt $assessments,
        int $id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $attempt = $assessments->start(
            $user,
            $id,
            $request->header('X-Session-Write-Token'),
        );

        return response()->json([
            'data' => AssessmentAttemptResource::make($attempt)->resolve($request),
        ], 201);
    }

    public function show(
        Request $request,
        ManageAssessmentAttempt $assessments,
        int $id,
    ): AssessmentAttemptResource {
        /** @var User $user */
        $user = $request->user();

        return AssessmentAttemptResource::make($assessments->findOwned($user, $id));
    }

    public function answer(
        SaveAssessmentAnswerRequest $request,
        ManageAssessmentAttempt $assessments,
        int $attemptId,
        int $itemId,
    ): AssessmentAttemptResource {
        /** @var User $user */
        $user = $request->user();

        return AssessmentAttemptResource::make($assessments->saveAnswer(
            $user,
            $attemptId,
            $itemId,
            $request->integer('selected_option_id'),
            $request->header('X-Session-Write-Token'),
        ));
    }

    public function submit(
        SubmitAssessmentAttemptRequest $request,
        ManageAssessmentAttempt $assessments,
        int $attemptId,
    ): AssessmentAttemptResource {
        /** @var User $user */
        $user = $request->user();

        return AssessmentAttemptResource::make($assessments->submit(
            $user,
            $attemptId,
            $request->header('X-Session-Write-Token'),
        ));
    }
}
