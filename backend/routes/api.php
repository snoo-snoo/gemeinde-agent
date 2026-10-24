<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MunicipalityController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/municipalities', [MunicipalityController::class, 'index']);
    Route::get('/municipalities/{municipality}', [MunicipalityController::class, 'show']);
    Route::get('/documents', [MunicipalityController::class, 'documents']);
    Route::get('/documents/{document}/file', [MunicipalityController::class, 'downloadDocument']);
    Route::get('/facts', [MunicipalityController::class, 'facts']);
    Route::get('/findings', [MunicipalityController::class, 'findings']);
    Route::get('/questions', [MunicipalityController::class, 'questions']);
    Route::get('/reports', [MunicipalityController::class, 'reports']);
    Route::get('/reports/{report}', [MunicipalityController::class, 'showReport']);
    Route::get('/reports/{report}/export', [MunicipalityController::class, 'exportReport']);
    Route::get('/analyses', [MunicipalityController::class, 'analyses']);
    Route::get('/validations', [MunicipalityController::class, 'validations']);
    Route::get('/agents', [SettingsController::class, 'agents']);
    Route::get('/agent-runs', [SettingsController::class, 'agentRuns']);
    Route::get('/usage', [SettingsController::class, 'usage']);
    Route::get('/providers', [SettingsController::class, 'providers']);
    Route::get('/schedules', [SettingsController::class, 'schedules']);

    Route::middleware('role:admin,analyst')->group(function () {
        Route::post('/municipalities', [MunicipalityController::class, 'store']);
        Route::post('/sources', [MunicipalityController::class, 'storeSource']);
        Route::post('/analyses', [MunicipalityController::class, 'startAnalysis']);
        Route::post('/analyses/{analysis}/cancel', [MunicipalityController::class, 'cancelAnalysis']);
        Route::post('/questions/{question}/answer', [MunicipalityController::class, 'answerQuestion']);
    });

    Route::middleware('role:admin,reviewer')->group(function () {
        Route::post('/findings/{finding}/review', [MunicipalityController::class, 'reviewFinding']);
        Route::post('/reports/{report}/approve', [MunicipalityController::class, 'approveReport']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::post('/providers', [SettingsController::class, 'storeProvider']);
        Route::patch('/providers/{provider}', [SettingsController::class, 'updateProvider']);
        Route::delete('/providers/{provider}', [SettingsController::class, 'destroyProvider']);
        Route::post('/providers/{provider}/credentials', [SettingsController::class, 'storeCredential']);
        Route::delete('/credentials/{credential}', [SettingsController::class, 'destroyCredential']);
        Route::post('/credentials/{credential}/test', [SettingsController::class, 'testCredential']);
        Route::post('/providers/{provider}/models', [SettingsController::class, 'storeModel']);
        Route::patch('/agents/{agent}', [SettingsController::class, 'updateAgent']);
        Route::post('/agents/{agent}/narrate', [SettingsController::class, 'narrate']);
        Route::get('/users', [SettingsController::class, 'users']);
        Route::post('/users', [SettingsController::class, 'storeUser']);
        Route::patch('/schedules/{schedule}', [SettingsController::class, 'updateSchedule']);
    });
});
