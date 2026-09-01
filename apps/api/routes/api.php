<?php

use App\Http\Controllers\AssessmentAttemptController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HeritageSiteController;
use App\Http\Controllers\LearningMaterialController;
use App\Http\Controllers\LearningSessionController;
use App\Http\Controllers\PanoramaNodeController;
use App\Http\Controllers\PanoramaVisitController;
use App\Http\Controllers\QuizAttemptController;
use App\Http\Controllers\QuizController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => [
    'data' => ['status' => 'ok'],
]);

Route::prefix('v1')->group(function () {
    Route::get('/heritage-sites', [HeritageSiteController::class, 'index']);
    Route::get('/heritage-sites/{slug}', [HeritageSiteController::class, 'show']);
    Route::get('/heritage-sites/{slug}/areas', [HeritageSiteController::class, 'areas']);
    Route::get('/panorama-nodes/{id}', [PanoramaNodeController::class, 'show']);
    Route::get('/heritage-objects/{id}/learning-material', [LearningMaterialController::class, 'show']);
    Route::get('/quizzes/{id}', [QuizController::class, 'show']);

    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/me/learning-sessions/active', [LearningSessionController::class, 'active']);
        Route::post('/learning-sessions', [LearningSessionController::class, 'store']);
        Route::get('/learning-sessions/{id}', [LearningSessionController::class, 'show']);
        Route::get('/learning-sessions/{id}/result', [LearningSessionController::class, 'result']);
        Route::post('/learning-sessions/{id}/panorama-visits', [PanoramaVisitController::class, 'store']);
        Route::post('/learning-sessions/{id}/materials/{objectId}/viewed', [LearningMaterialController::class, 'viewed']);
        Route::post('/learning-sessions/{id}/quiz-attempts', [QuizAttemptController::class, 'store']);
        Route::put('/quiz-attempts/{attemptId}/answers/{questionId}', [QuizAttemptController::class, 'answer']);
        Route::post('/quiz-attempts/{attemptId}/submit', [QuizAttemptController::class, 'submit']);
        Route::post('/learning-sessions/{id}/assessment-attempts', [AssessmentAttemptController::class, 'store']);
        Route::get('/assessment-attempts/{id}', [AssessmentAttemptController::class, 'show']);
        Route::put('/assessment-attempts/{attemptId}/answers/{itemId}', [AssessmentAttemptController::class, 'answer']);
        Route::post('/assessment-attempts/{attemptId}/submit', [AssessmentAttemptController::class, 'submit']);
    });
});
