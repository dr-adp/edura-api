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

class QuizAttemptController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = QuizAttempt::with([
            'quiz.course',
            'studentProfile.user',
            'studentProfile.batch'
        ]);

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

            $parentProfile = $user->parentProfile;

            if (!$parentProfile) {

                abort(
                    403,
                    'Parent profile not found.'
                );
            }

            $query->where(
                'student_profile_id',
                $parentProfile->student_profile_id
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
            )->first();

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

        return response()->json([
            'message' => 'Quiz attempts fetched successfully.',
            'data' => $query
                ->latest()
                ->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quiz_id' => ['required', 'exists:quizzes,id'],
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $quiz = Quiz::with('course')
            ->findOrFail(
                $validated['quiz_id']
            );

        $studentProfile = StudentProfile::findOrFail(
            $validated['student_profile_id']
        );

        /*
    |--------------------------------------------------------------------------
    | Student Ownership
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {

            $currentStudent = StudentProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$currentStudent ||
                (int) $currentStudent->id !==
                (int) $studentProfile->id
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
            )->first();

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
            $studentProfile
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
        $currentAttempts = QuizAttempt::where(
            'quiz_id',
            $quiz->id
        )
            ->where(
                'student_profile_id',
                $studentProfile->id
            )
            ->count();

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
        $hasOpenAttempt = QuizAttempt::where(
            'quiz_id',
            $quiz->id
        )
            ->where(
                'student_profile_id',
                $studentProfile->id
            )
            ->where(
                'status',
                'in_progress'
            )
            ->exists();

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
        $lastAttemptNumber = QuizAttempt::where(
            'quiz_id',
            $quiz->id
        )
            ->where(
                'student_profile_id',
                $studentProfile->id
            )
            ->max(
                'attempt_number'
            );

        $attempt = QuizAttempt::create([
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
        $this->authorizeQuizAttemptAccess($quizAttempt);

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
        Request $request,
        QuizAttempt $quizAttempt
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Centralized Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeQuizAttemptAccess(
            $quizAttempt
        );

        /*
    |--------------------------------------------------------------------------
    | Lock Final States
    |--------------------------------------------------------------------------
    */
        if (
            in_array(
                $quizAttempt->status,
                [
                    'evaluated',
                    'cancelled',
                ],
                true
            )
        ) {

            abort(
                403,
                'This attempt can no longer be modified.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'status' => [
                'required',
                'in:in_progress,submitted',
            ],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Students
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {

            /*
        |--------------------------------------------------------------------------
        | Students cannot reopen submitted attempts
        |--------------------------------------------------------------------------
        */
            if (
                $quizAttempt->status === 'submitted'
            ) {

                abort(
                    403,
                    'Submitted attempts cannot be modified.'
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Students may only submit
        |--------------------------------------------------------------------------
        */
            if (
                $validated['status'] !== 'submitted'
            ) {

                abort(
                    403,
                    'Invalid status transition.'
                );
            }

            $validated['submitted_at'] = now();
        }

        /*
    |--------------------------------------------------------------------------
    | Parents
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('parent')) {

            abort(
                403,
                'Parents cannot modify quiz attempts.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Teachers / Institution Admins / Super Admins
    |--------------------------------------------------------------------------
    */
        if (
            $user->hasAnyRole([
                'teacher',
                'institution-admin',
                'super-admin',
            ])
        ) {

            /*
        |--------------------------------------------------------------------------
        | Staff cannot manually evaluate attempts
        |--------------------------------------------------------------------------
        | Evaluation is derived from QuizAnswerController.
        |--------------------------------------------------------------------------
        */
            if (
                $validated['status'] === 'submitted'
            ) {

                abort(
                    403,
                    'Staff cannot submit attempts.'
                );
            }
        }

        $quizAttempt->update(
            $validated
        );

        return response()->json([
            'message' => 'Quiz attempt updated successfully.',
            'data' => $quizAttempt
                ->fresh()
                ->load([
                    'quiz.course',
                    'studentProfile.user',
                    'studentProfile.batch',
                ]),
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

        /*
    |--------------------------------------------------------------------------
    | Institution Admin Scope
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                $quizAttempt->studentProfile->institution_id !==
                $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }
        }

        $quizAttempt->delete();

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

        /*
    |--------------------------------------------------------------------------
    | Institution Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

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

            $studentProfile = StudentProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$studentProfile ||
                (int) $studentProfile->id !==
                (int) $quizAttempt->student_profile_id
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

            $parentProfile = $user->parentProfile;

            if (
                !$parentProfile ||
                (int) $parentProfile->student_profile_id !==
                (int) $quizAttempt->student_profile_id
            ) {

                abort(
                    403,
                    'Unauthorized parent access.'
                );
            }

            return;
        }

        abort(
            403,
            'Unauthorized role.'
        );
    }

    private function validateQuizAttemptEnrollment(
        Quiz $quiz,
        StudentProfile $studentProfile
    ): void {

        $enrolled = CourseEnrollment::where(
            'course_id',
            $quiz->course_id
        )
            ->where(
                'student_profile_id',
                $studentProfile->id
            )
            ->whereIn(
                'status',
                [
                    'active',
                    'completed',
                ]
            )
            ->exists();

        if (!$enrolled) {

            abort(
                403,
                'Student is not enrolled in this course.'
            );
        }
    }
}
