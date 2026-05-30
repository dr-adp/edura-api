<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\InstitutionController;
use App\Http\Controllers\Api\InstitutionUserController;
use App\Http\Controllers\Api\ParentProfileController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\TeacherProfileController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\InstitutionSubscriptionController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\CourseSectionController;

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

    Route::apiResource('institution-users', InstitutionUserController::class);

    Route::apiResource('teacher-profiles', TeacherProfileController::class);
    Route::apiResource('student-profiles', StudentProfileController::class);
    Route::apiResource('parent-profiles', ParentProfileController::class);

    Route::post('/upload/profile-photo', [UploadController::class, 'uploadProfilePhoto']);
    Route::post('/institutions/{institution}/upload-logo', [UploadController::class, 'uploadInstitutionLogo']);

    Route::apiResource('courses', CourseController::class);
    Route::apiResource('course-sections', CourseSectionController::class);
});