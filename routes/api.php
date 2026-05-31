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
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\LessonResourceController;
use App\Http\Controllers\Api\CourseEnrollmentController;
use App\Http\Controllers\Api\LessonProgressController;
use App\Http\Controllers\Api\LiveClassController;
use App\Http\Controllers\Api\LiveClassAttendanceController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AssignmentSubmissionController;
use App\Http\Controllers\Api\AssignmentEvaluationController;
use App\Http\Controllers\Api\QuestionBankController;
use App\Http\Controllers\Api\QuestionOptionController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\QuizQuestionController;
use App\Http\Controllers\Api\QuizAttemptController;

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
    Route::apiResource('lessons', LessonController::class);

    Route::apiResource('lesson-resources', LessonResourceController::class);
    Route::apiResource('course-enrollments', CourseEnrollmentController::class);
    Route::apiResource('lesson-progress', LessonProgressController::class);
    Route::apiResource('live-classes', LiveClassController::class);
    
    Route::apiResource('live-class-attendances', LiveClassAttendanceController::class);
    Route::apiResource('assignments', AssignmentController::class);
    Route::apiResource('assignment-submissions', AssignmentSubmissionController::class);
    Route::apiResource('assignment-evaluations', AssignmentEvaluationController::class);

    Route::apiResource('question-banks', QuestionBankController::class);
    Route::apiResource('question-options', QuestionOptionController::class);
    Route::apiResource('quizzes', QuizController::class);
    Route::apiResource('quiz-questions', QuizQuestionController::class);
    Route::apiResource('quiz-attempts', QuizAttemptController::class);


});
