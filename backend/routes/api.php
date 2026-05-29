<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutobiographyController;
use App\Http\Controllers\Api\V1\DebugController;
use App\Http\Controllers\Api\V1\EarthController;
use App\Http\Controllers\Api\V1\HumanController;
use App\Http\Controllers\Api\V1\InterviewSessionController;
use App\Http\Controllers\Api\V1\PsychologistController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SkyController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/settings/reset', [SettingsController::class, 'reset']);

        Route::prefix('interview')->group(function () {
            Route::get('/sessions', [InterviewSessionController::class, 'index']);
            Route::post('/sessions', [InterviewSessionController::class, 'store']);
            Route::get('/sessions/{id}', [InterviewSessionController::class, 'show']);
            Route::post('/sessions/{id}/messages', [InterviewSessionController::class, 'storeMessage']);
            Route::post('/sessions/{id}/messages/stream', [InterviewSessionController::class, 'streamMessage']);
            Route::post('/sessions/{id}/upload', [InterviewSessionController::class, 'upload']);
            Route::get('/sessions/{id}/extractions', [InterviewSessionController::class, 'extractions']);
        });

        Route::get('/earth/timeline', [EarthController::class, 'timeline']);
        Route::get('/human/bridge', [HumanController::class, 'bridge']);
        Route::get('/sky/graph', [SkyController::class, 'graph']);
        Route::get('/sky/patterns', [SkyController::class, 'patterns']);

        Route::prefix('autobiographies')->group(function () {
            Route::get('/', [AutobiographyController::class, 'index']);
            Route::post('/generate', [AutobiographyController::class, 'store']);
            Route::get('/{id}', [AutobiographyController::class, 'show']);
            Route::post('/{id}/versions', [AutobiographyController::class, 'createVersion']);
            Route::get('/{id}/compare/{otherId}', [AutobiographyController::class, 'compare']);
            Route::get('/{id}/export.md', [AutobiographyController::class, 'exportMarkdown']);
        });

        Route::prefix('psychologist')->group(function () {
            Route::get('/sessions', [PsychologistController::class, 'index']);
            Route::post('/sessions', [PsychologistController::class, 'store']);
            Route::post('/sessions/{id}/messages', [PsychologistController::class, 'storeMessage']);
            Route::post('/sessions/{id}/messages/stream', [PsychologistController::class, 'streamMessage']);
        });

        Route::prefix('debug')->group(function () {
            Route::get('/ai-logs', [DebugController::class, 'aiLogs']);
            Route::get('/jobs-logs', [DebugController::class, 'jobsLogs']);
            Route::get('/projections', [DebugController::class, 'projections']);
            Route::post('/rebuild-graph', [DebugController::class, 'rebuildGraph']);
        });
    });
});
