<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\InstitutionController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\InstitutionSubscriptionController;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'EDURA API',
        'company' => 'AGHORI',
    ]);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('institutions', InstitutionController::class);
    Route::apiResource('subscription-plans', SubscriptionPlanController::class);
    Route::apiResource('institution-subscriptions', InstitutionSubscriptionController::class);
    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('batches', BatchController::class);
});
