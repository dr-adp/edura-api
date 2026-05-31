<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\QuizAnswerController;
use App\Http\Controllers\Api\QuizAttemptController;
use App\Http\Controllers\Api\QuizQuestionController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\InstitutionController;
use App\Http\Controllers\Api\QuestionBankController;
use App\Http\Controllers\Api\QuestionOptionController;
use App\Http\Controllers\Api\CourseSectionController;
use App\Http\Controllers\Api\LessonProgressController;
use App\Http\Controllers\Api\LessonResourceController;
use App\Http\Controllers\Api\LiveClassController;
use App\Http\Controllers\Api\LiveClassAttendanceController;
use App\Http\Controllers\Api\CourseEnrollmentController;
use App\Http\Controllers\Api\InstitutionUserController;
use App\Http\Controllers\Api\ParentProfileController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\TeacherProfileController;
use App\Http\Controllers\Api\AssignmentSubmissionController;
use App\Http\Controllers\Api\AssignmentEvaluationController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\InstitutionSubscriptionController;
use App\Http\Controllers\Api\GradebookController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CertificateSettingController;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'EDURA API',
        'company' => 'AGHORI',
    ]);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/upload/profile-photo', [UploadController::class, 'uploadProfilePhoto']);

    Route::middleware(['role:super-admin'])->group(function () {
        Route::apiResource('institutions', InstitutionController::class);
        Route::apiResource('subscription-plans', SubscriptionPlanController::class);
        Route::apiResource('institution-subscriptions', InstitutionSubscriptionController::class);
        Route::post('/institutions/{institution}/upload-logo', [UploadController::class, 'uploadInstitutionLogo']);
    });

    Route::middleware(['role:super-admin|institution-admin'])->group(function () {
        Route::apiResource('departments', DepartmentController::class);
        Route::apiResource('batches', BatchController::class);
        Route::apiResource('institution-users', InstitutionUserController::class);
        Route::apiResource('teacher-profiles', TeacherProfileController::class);
        Route::apiResource('student-profiles', StudentProfileController::class);
        Route::apiResource('parent-profiles', ParentProfileController::class);
        Route::apiResource('course-enrollments', CourseEnrollmentController::class);
    });

    Route::middleware(['role:super-admin|institution-admin|teacher'])->group(function () {
        Route::apiResource('courses', CourseController::class);
        Route::apiResource('course-sections', CourseSectionController::class);
        Route::apiResource('lessons', LessonController::class);
        Route::apiResource('lesson-resources', LessonResourceController::class);

        Route::apiResource('live-classes', LiveClassController::class);
        Route::apiResource('live-class-attendances', LiveClassAttendanceController::class);

        Route::apiResource('assignments', AssignmentController::class);
        Route::apiResource('assignment-evaluations', AssignmentEvaluationController::class);

        Route::apiResource('question-banks', QuestionBankController::class);
        Route::apiResource('question-options', QuestionOptionController::class);
        Route::apiResource('quizzes', QuizController::class);
        Route::apiResource('quiz-questions', QuizQuestionController::class);

        Route::post('/gradebooks/recalculate', [GradebookController::class, 'recalculate']);
        Route::apiResource('gradebooks', GradebookController::class);


        Route::post('/certificates/{certificate}/generate', [CertificateController::class, 'generate']);
        Route::get('/certificates/{certificate}/download', [CertificateController::class, 'download']);
        Route::apiResource('certificates', CertificateController::class);
        Route::apiResource('certificate-settings', CertificateSettingController::class);
    });

    Route::middleware(['role:super-admin|institution-admin|teacher|student'])->group(function () {
        Route::apiResource('lesson-progress', LessonProgressController::class);
        Route::apiResource('assignment-submissions', AssignmentSubmissionController::class);
        Route::apiResource('quiz-attempts', QuizAttemptController::class);
        Route::apiResource('quiz-answers', QuizAnswerController::class);
    });

    Route::middleware(['role:super-admin|institution-admin|teacher|student|parent'])->group(function () {
        Route::get('/my-gradebooks', [GradebookController::class, 'index']);
        Route::get('/my-certificates', [CertificateController::class, 'index']);
    });
});
