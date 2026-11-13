<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstitutionUser;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\CourseEnrollment;
use App\Http\Requests\StoreQuizAttemptRequest;
use App\Http\Requests\UpdateQuizAttemptRequest;
use App\Services\QuizAttemptGradingService;
use App\Services\CourseAssessmentLockService;
use App\Services\ParentStudentAccessService;

class QuizAttemptController extends Controller
{
    public function __construct(
        private QuizAttemptGradingService $quizAttemptGradingService,
        private CourseAssessmentLockService $courseAssessmentLockService,
        private ParentStudentAccessService $parentStudentAccessService
    ) {
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = QuizAttempt::with([
            'quiz.course',
            'studentProfile.user',
            'studentProfile.batch'
        ]);

        if (!$user->hasRole('super-admin')) {
            $query->institutionallyConsistent();
        }

        /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {

            $studentProfile = StudentProfile::where(
                'user_id',
                $user->id
            )->first();

            if (!$studentProfile) {

                abort(
                    403,
                    'Student profile not found.'
                );
            }

            $query->where(
                'student_profile_id',
                $studentProfile->id
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Parent
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('parent')) {

            $this->parentStudentAccessService->scopeStudentRecords(
                $query,
                $user,
                'quiz_attempts.student_profile_id'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            $query->whereHas(
                'quiz.course',
                function ($q) use ($teacherProfile) {

                    $q->where(
                        'teacher_profile_id',
                        $teacherProfile->id
                    );
                }
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )
                ->where('status', 'active')
                ->first();

            if (!$institutionUser) {

                abort(
                    403,
                    'Institution profile not found.'
                );
            }

            $query->whereHas(
                'quiz.course',
                function ($q) use ($institutionUser) {

                    $q->where(
                        'institution_id',
                        $institutionUser->institution_id
                    );
                }
            )->whereHas(
                'studentProfile',
                function ($q) use ($institutionUser) {

                    $q->where(
                        'institution_id',
                        $institutionUser->institution_id
                    );
                }
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    */ elseif (!$user->hasRole('super-admin')) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        if ($this->shouldRedactPendingManualResults($user)) {
            $this->quizAttemptGradingService
                ->withPendingManualAnswersFlag($query);
        }

        $attempts = $query
            ->latest()
            ->paginate(20);

        $attempts->getCollection()->each(function (QuizAttempt $attempt) use ($user) {
            $this->sanitizeAttemptForUser($attempt, $user);
        });

        return response()->json([
            'message' => 'Quiz attempts fetched successfully.',
            'data' => $attempts,
        ]);
    }

    public function store(StoreQuizAttemptRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $user */
        $user = Auth::user();

        $attempt = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($validated, $user): QuizAttempt {
                $locked = $this->courseAssessmentLockService
                    ->lockQuizForAttemptCreation(
                        (int) $validated['quiz_id'],
                        (int) $validated['student_profile_id']
                    );
                /** @var Quiz $quiz */
                $quiz = $locked['quiz'];
                /** @var StudentProfile $studentProfile */
                $studentProfile = $locked['student'];
                /** @var CourseEnrollment|null $enrollment */
                $enrollment = $locked['enrollment'];
                $attempts = $locked['attempts'];

        /*
    |--------------------------------------------------------------------------
    | Student Ownership
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {
            if (
                (int) $studentProfile->user_id !== (int) $user->id
            ) {

                abort(
                    403,
                    'You may only create quiz attempts for yourself.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Parent Cannot Create Attempts
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('parent')) {

            abort(
                403,
                'Parents cannot create quiz attempts.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin Scope
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )
                ->where('status', 'active')
                ->first();

            if (!$institutionUser) {

                abort(
                    403,
                    'Institution profile not found.'
                );
            }

            if (
                (int) $studentProfile->institution_id !==
                (int) $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }

            if (
                (int) $quiz->course->institution_id !==
                (int) $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher Ownership
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            if (
                (int) $quiz->course->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized teacher access.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Enrollment Validation
    |--------------------------------------------------------------------------
    */
        $this->validateQuizAttemptEnrollment(
            $quiz,
            $studentProfile,
            $enrollment
        );

        /*
    |--------------------------------------------------------------------------
    | Quiz Availability
    |--------------------------------------------------------------------------
    */
        if (
            $quiz->available_from &&
            now()->lt($quiz->available_from)
        ) {

            abort(
                403,
                'This quiz is not yet available.'
            );
        }

        if (
            $quiz->available_until &&
            now()->gt($quiz->available_until)
        ) {

            abort(
                403,
                'This quiz has expired.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Maximum Attempts
    |--------------------------------------------------------------------------
    */
        $activeAttempts = $attempts->filter(
            static fn (QuizAttempt $existing): bool => $existing->deleted_at === null
        );
        $currentAttempts = $activeAttempts->count();

        if (
            $quiz->maximum_attempts &&
            $currentAttempts >= $quiz->maximum_attempts
        ) {

            abort(
                403,
                'Maximum number of attempts reached.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Existing In Progress Attempt
    |--------------------------------------------------------------------------
    */
        $hasOpenAttempt = $activeAttempts->contains(
            static fn (QuizAttempt $existing): bool => $existing->status === 'in_progress'
        );

        if ($hasOpenAttempt) {

            abort(
                403,
                'You already have an unfinished attempt.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Attempt Number
    |--------------------------------------------------------------------------
    */
        $lastAttemptNumber = $attempts->max('attempt_number');

                return QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'student_profile_id' => $studentProfile->id,
                'attempt_number' => ($lastAttemptNumber ?? 0) + 1,
                'started_at' => now(),
                'total_marks' => $quiz->total_marks,
                'marks_obtained' => 0,
                'percentage' => 0,
                'result_status' => 'pending',
                'status' => 'in_progress',
            ]);
            });

        $this->sanitizeAttemptForUser($attempt, $user);

        return response()->json([
            'message' => 'Quiz attempt started successfully.',
            'data' => $attempt->load([
                'quiz.course',
                'studentProfile.user',
                'studentProfile.batch',
            ]),
        ], 201);
    }

    public function show(QuizAttempt $quizAttempt): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $this->authorizeQuizAttemptAccess($quizAttempt);
        $this->sanitizeAttemptForUser($quizAttempt, $user);

        return response()->json([
            'message' => 'Quiz attempt fetched successfully.',
            'data' => $quizAttempt->load([
                'quiz.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function update(
        UpdateQuizAttemptRequest $request,
        QuizAttempt $quizAttempt
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validated();
        $attemptId = (int) $quizAttempt->id;
        $attempt = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use (
                $attemptId,
                $validated,
                $user
            ): QuizAttempt {
                $attempt = $this->courseAssessmentLockService
                    ->lockAttemptForMutation($attemptId);

                $this->authorizeQuizAttemptAccess($attempt);
                $this->validateLockedAttemptEnrollment($attempt);

                if (in_array($attempt->status, ['evaluated', 'cancelled'], true)) {
                    abort(403, 'This attempt can no longer be modified.');
                }

                if ($user->hasRole('student')) {
                    if ($attempt->status === 'submitted') {
                        abort(403, 'Submitted attempts cannot be modified.');
                    }

                    if ($validated['status'] !== 'submitted') {
                        abort(403, 'Invalid status transition.');
                    }

                    $validated['submitted_at'] = now();
                }

                if ($user->hasRole('parent')) {
                    abort(403, 'Parents cannot modify quiz attempts.');
                }

                if (
                    $user->hasAnyRole([
                        'teacher',
                        'institution-admin',
                        'super-admin',
                    ]) &&
                    $validated['status'] === 'submitted'
                ) {
                    abort(403, 'Staff cannot submit attempts.');
                }

                $attempt->update($validated);

                if ($validated['status'] === 'submitted') {
                    $attempt->setRelation(
                        'answers',
                        $this->courseAssessmentLockService
                            ->lockQuizAnswersForAttempt($attemptId)
                    );
                    $this->quizAttemptGradingService->recalculate($attempt);
                }

                return $attempt->loadMissing([
                    'quiz.course',
                    'studentProfile.user',
                    'studentProfile.batch',
                ]);
            });

        $this->sanitizeAttemptForUser($attempt, $user);

        return response()->json([
            'message' => 'Quiz attempt updated successfully.',
            'data' => $attempt,
        ]);
    }

    private function sanitizeAttemptForUser(QuizAttempt $attempt, User $user): void
    {
        if (! $this->shouldRedactPendingManualResults($user)) {
            return;
        }

        $this->quizAttemptGradingService->redactPendingAttemptResults($attempt);
    }

    private function shouldRedactPendingManualResults(User $user): bool
    {
        return $user->hasAnyRole(['student', 'parent']) &&
            ! $user->hasAnyRole([
                'super-admin',
                'institution-admin',
                'teacher',
            ]);
    }

    public function destroy(QuizAttempt $quizAttempt): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->hasAnyRole([
            'super-admin',
            'institution-admin'
        ])) {

            abort(
                403,
                'Unauthorized: Only admins can delete quiz attempts.'
            );
        }

        $attemptId = (int) $quizAttempt->id;
        $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($attemptId): void {
                $attempt = $this->courseAssessmentLockService
                    ->lockAttemptForMutation($attemptId);

                $this->authorizeQuizAttemptAccess($attempt);
                $this->validateLockedAttemptEnrollment($attempt);
                $this->courseAssessmentLockService
                    ->lockQuizAnswersForAttempt($attemptId);
                $attempt->delete();
            });

        return response()->json([
            'message' => 'Quiz attempt deleted successfully.',
        ]);
    }

    private function authorizeQuizAttemptAccess(
        QuizAttempt $quizAttempt
    ): void {

        /** @var User $user */
        $user = Auth::user();

        $quizAttempt->loadMissing([
            'quiz.course',
            'studentProfile',
        ]);

        /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('super-admin')) {
            return;
        }

        if (!$quizAttempt->hasConsistentInstitutions()) {
            abort(403, 'Course and student must belong to the same institution.');
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )
                ->where('status', 'active')
                ->first();

            if (!$institutionUser) {

                abort(
                    403,
                    'Institution profile not found.'
                );
            }

            $studentInstitutionId =
                $quizAttempt->studentProfile?->institution_id;

            $courseInstitutionId =
                $quizAttempt->quiz?->course?->institution_id;

            if (
                !$studentInstitutionId ||
                !$courseInstitutionId ||
                (int) $studentInstitutionId !==
                (int) $institutionUser->institution_id ||
                (int) $courseInstitutionId !==
                (int) $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }

            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            $courseTeacherId =
                $quizAttempt->quiz?->course?->teacher_profile_id;

            if (
                !$courseTeacherId ||
                (int) $courseTeacherId !==
                (int) $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized teacher access.'
                );
            }

            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {
            if (
                ! $quizAttempt->studentProfile ||
                (int) $quizAttempt->studentProfile->user_id !== (int) $user->id
            ) {

                abort(
                    403,
                    'Unauthorized student access.'
                );
            }

            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Parent
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('parent')) {

            $this->parentStudentAccessService->authorizeStudent(
                $user,
                (int) $quizAttempt->student_profile_id,
                'Unauthorized parent access.'
            );

            return;
        }

        abort(
            403,
            'Unauthorized role.'
        );
    }

    private function validateQuizAttemptEnrollment(
        Quiz $quiz,
        StudentProfile $studentProfile,
        ?CourseEnrollment $enrollment
    ): void {

        $course = $quiz->course;
        $enrolled = $enrollment &&
            ! $enrollment->trashed() &&
            (int) $enrollment->course_id === (int) $quiz->course_id &&
            (int) $enrollment->student_profile_id === (int) $studentProfile->id &&
            in_array($enrollment->status, ['active', 'completed'], true) &&
            $course &&
            $course->institution_id !== null &&
            $studentProfile->institution_id !== null &&
            (int) $course->institution_id === (int) $studentProfile->institution_id;

        if (!$enrolled) {

            abort(
                403,
                'Student is not enrolled in this course.'
            );
        }
    }

    private function validateLockedAttemptEnrollment(QuizAttempt $attempt): void
    {
        $quiz = $attempt->quiz;
        $course = $quiz?->course;
        $student = $attempt->studentProfile;
        $enrollment = $attempt->getRelation('courseEnrollment');

        $eligible = $quiz &&
            $course &&
            $student &&
            $enrollment instanceof CourseEnrollment &&
            ! $enrollment->trashed() &&
            (int) $enrollment->course_id === (int) $course->id &&
            (int) $enrollment->student_profile_id === (int) $student->id &&
            in_array($enrollment->status, ['active', 'completed'], true) &&
            $course->institution_id !== null &&
            $student->institution_id !== null &&
            (int) $course->institution_id === (int) $student->institution_id;

        if (! $eligible) {
            abort(403, 'Student is not enrolled in this course.');
        }
    }
}
