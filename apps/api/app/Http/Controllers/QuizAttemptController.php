<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\ManageQuizAttempt;
use App\Http\Requests\SaveQuizAnswerRequest;
use App\Http\Requests\StartQuizAttemptRequest;
use App\Http\Requests\SubmitQuizAttemptRequest;
use App\Http\Resources\QuizAnswerResource;
use App\Http\Resources\QuizAttemptResource;
use Illuminate\Http\JsonResponse;

class QuizAttemptController extends Controller
{
    public function store(
        StartQuizAttemptRequest $request,
        ManageQuizAttempt $quizAttempts,
        int $id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $attempt = $quizAttempts->start(
            $user,
            $id,
            $request->integer('quiz_id'),
            $request->header('X-Session-Write-Token'),
        );

        return response()->json([
            'data' => QuizAttemptResource::make($attempt)->resolve($request),
        ], 201);
    }

    public function answer(
        SaveQuizAnswerRequest $request,
        ManageQuizAttempt $quizAttempts,
        int $attemptId,
        int $questionId,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $answer = $quizAttempts->answer(
            $user,
            $attemptId,
            $questionId,
            $request->integer('selected_option_id'),
            $request->header('X-Session-Write-Token'),
        );

        return response()->json([
            'data' => QuizAnswerResource::make($answer)->resolve($request),
        ]);
    }

    public function submit(
        SubmitQuizAttemptRequest $request,
        ManageQuizAttempt $quizAttempts,
        int $attemptId,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => QuizAttemptResource::make($quizAttempts->submit(
                $user,
                $attemptId,
                $request->header('X-Session-Write-Token'),
            ))->resolve($request),
        ]);
    }
}
