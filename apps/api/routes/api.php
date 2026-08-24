<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HeritageSiteController;
use App\Http\Controllers\LearningSessionController;
use App\Http\Controllers\PanoramaNodeController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => [
    'data' => ['status' => 'ok'],
]);

Route::prefix('v1')->group(function () {
    Route::get('/heritage-sites', [HeritageSiteController::class, 'index']);
    Route::get('/heritage-sites/{slug}', [HeritageSiteController::class, 'show']);
    Route::get('/heritage-sites/{slug}/areas', [HeritageSiteController::class, 'areas']);
    Route::get('/panorama-nodes/{id}', [PanoramaNodeController::class, 'show']);

    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/me/learning-sessions/active', [LearningSessionController::class, 'active']);
        Route::post('/learning-sessions', [LearningSessionController::class, 'store']);
        Route::get('/learning-sessions/{id}', [LearningSessionController::class, 'show']);
    });
});
