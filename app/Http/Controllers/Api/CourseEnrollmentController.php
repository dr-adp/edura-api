<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\DB;
use App\Services\CourseEnrollmentService;
use App\Models\CourseEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class CourseEnrollmentController extends Controller
{
    protected CourseEnrollmentService $service;
    public function __construct(
        CourseEnrollmentService $service
    ) {
        $this->service = $service;
    }
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = CourseEnrollment::with([
            'course',
            'studentProfile.user',
            'studentProfile.institution',
            'studentProfile.batch',
        ]);

        if ($user->hasRole('super-admin')) {

            // Full access

        } elseif ($user->hasRole('institution-admin')) {

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
                'course',
                fn($q) => $q->where(
                    'institution_id',
                    $institutionUser->institution_id
                )
            );
        } elseif ($user->hasRole('teacher')) {

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
                'course',
                fn($q) => $q->where(
                    'teacher_profile_id',
                    $teacherProfile->id
                )
            );
        } elseif ($user->hasRole('student')) {

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
        } else {

            abort(
                403,
                'Unauthorized.'
            );
        }

        $enrollments = $query
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Course enrollments fetched successfully.',
            'data' => $enrollments,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],

            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
                Rule::unique('course_enrollments', 'student_profile_id')
                    ->where('course_id', $request->course_id),
            ],

            'enrollment_date' => ['nullable', 'date'],
            'payment_status' => ['nullable', 'in:free,pending,paid,failed,refunded'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'progress_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'in:active,completed,cancelled,expired'],
            'completed_at' => ['nullable', 'date'],
        ]);

        $validated['enrollment_date'] =
            $validated['enrollment_date']
            ?? now()->toDateString();

        $course = Course::findOrFail(
            $validated['course_id']
        );

        $studentProfile = StudentProfile::findOrFail(
            $validated['student_profile_id']
        );

        $this->authorizeCourseEnrollmentManagement(
            $course,
            $studentProfile
        );

        $enrollment = $this->service->create(
            $validated
        );

        return response()->json([
            'message' => 'Student enrolled in course successfully.',
            'data' => $enrollment->load([
                'course',
                'studentProfile.user',
                'studentProfile.institution',
                'studentProfile.batch',
            ]),
        ], 201);
    }

    public function show(
        CourseEnrollment $courseEnrollment
    ): JsonResponse {

        $this->authorizeCourseEnrollmentAccess(
            $courseEnrollment
        );

        return response()->json([
            'message' => 'Course enrollment fetched successfully.',
            'data' => $courseEnrollment->load([
                'course',
                'studentProfile.user',
                'studentProfile.institution',
                'studentProfile.batch',
            ]),
        ]);
    }

    public function update(
        Request $request,
        CourseEnrollment $courseEnrollment
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Access Validation
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseEnrollmentAccess(
            $courseEnrollment
        );

        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'course_id' => [
                'sometimes',
                'exists:courses,id'
            ],

            'student_profile_id' => [
                'sometimes',
                'exists:student_profiles,id',
                Rule::unique(
                    'course_enrollments',
                    'student_profile_id'
                )
                    ->where(
                        'course_id',
                        $request->course_id
                            ?? $courseEnrollment->course_id
                    )
                    ->ignore(
                        $courseEnrollment->id
                    ),
            ],

            'enrollment_date' => [
                'nullable',
                'date'
            ],

            'payment_status' => [
                'nullable',
                'in:free,pending,paid,failed,refunded'
            ],

            'amount_paid' => [
                'nullable',
                'numeric',
                'min:0'
            ],

            'progress_percentage' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100'
            ],

            'status' => [
                'nullable',
                'in:active,completed,cancelled,expired'
            ],

            'completed_at' => [
                'nullable',
                'date'
            ],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Resolve Target Records
    |--------------------------------------------------------------------------
    */
        $course = Course::findOrFail(
            $validated['course_id']
                ?? $courseEnrollment->course_id
        );

        $studentProfile = StudentProfile::findOrFail(
            $validated['student_profile_id']
                ?? $courseEnrollment->student_profile_id
        );

        /*
    |--------------------------------------------------------------------------
    | Management Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseEnrollmentManagement(
            $course,
            $studentProfile
        );

        /*
    |--------------------------------------------------------------------------
    | Students Cannot Modify Enrollment
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {

            abort(
                403,
                'Students cannot modify enrollments.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Parent Restriction
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('parent')) {

            abort(
                403,
                'Parents cannot modify enrollments.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Auto Completion
    |--------------------------------------------------------------------------
    */
        if (
            isset(
                $validated['progress_percentage']
            ) &&
            (float) $validated['progress_percentage'] >= 100
        ) {

            $validated['progress_percentage'] = 100;

            $validated['status'] = 'completed';

            $validated['completed_at'] = now();
        }

        /*
    |--------------------------------------------------------------------------
    | Prevent Invalid Completion State
    |--------------------------------------------------------------------------
    */
        if (
            ($validated['status'] ?? null)
            === 'completed'
            &&
            empty($validated['completed_at'])
        ) {

            $validated['completed_at'] = now();
        }

        /*
    |--------------------------------------------------------------------------
    | Prevent Negative Business Logic
    |--------------------------------------------------------------------------
    */
        if (
            isset(
                $validated['amount_paid']
            )
            &&
            $validated['amount_paid'] > 0
            &&
            (
                $validated['payment_status']
                ?? $courseEnrollment->payment_status
            ) === 'free'
        ) {

            abort(
                422,
                'Free enrollment cannot contain paid amount.'
            );
        }

        $courseEnrollment->update(
            $validated
        );

        return response()->json([
            'message' => 'Course enrollment updated successfully.',
            'data' => $courseEnrollment
                ->fresh()
                ->load([
                    'course',
                    'studentProfile.user',
                    'studentProfile.institution',
                    'studentProfile.batch',
                ]),
        ]);
    }

    public function destroy(
        CourseEnrollment $courseEnrollment
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Access Validation
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseEnrollmentAccess(
            $courseEnrollment
        );

        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Parent Restriction
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('parent')) {

            abort(
                403,
                'Parents cannot delete enrollments.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Student Restriction
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {

            abort(
                403,
                'Students cannot delete enrollments.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Protect Completed Enrollments
    |--------------------------------------------------------------------------
    */
        if (
            $courseEnrollment->status === 'completed'
        ) {

            abort(
                422,
                'Completed enrollments cannot be deleted.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Soft Delete
    |--------------------------------------------------------------------------
    */
        $courseEnrollment->delete();

        return response()->json([
            'message' => 'Course enrollment deleted successfully.',
        ]);
    }

    private function authorizeCourseEnrollmentAccess(
        CourseEnrollment $enrollment
    ): void {

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        $enrollment->loadMissing([
            'course',
            'studentProfile',
        ]);

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                (int) $enrollment->course->institution_id !==
                (int) $institutionUser->institution_id ||
                (int) $enrollment->studentProfile->institution_id !==
                (int) $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }

            return;
        }

        if ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$teacherProfile ||
                (int) $enrollment->course->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized course access.'
                );
            }

            return;
        }

        if ($user->hasRole('student')) {

            $studentProfile = StudentProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$studentProfile ||
                (int) $studentProfile->id !==
                (int) $enrollment->student_profile_id
            ) {

                abort(
                    403,
                    'Unauthorized enrollment access.'
                );
            }

            return;
        }

        if ($user->hasRole('parent')) {

            abort(
                403,
                'Parents cannot access course enrollments.'
            );
        }

        abort(
            403,
            'Unauthorized.'
        );
    }

    private function authorizeCourseEnrollmentManagement(
        Course $course,
        StudentProfile $studentProfile
    ): void {

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        if (
            (int) $course->institution_id !==
            (int) $studentProfile->institution_id
        ) {

            abort(
                422,
                'Course and student must belong to the same institution.'
            );
        }

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                (int) $institutionUser->institution_id !==
                (int) $course->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized institution access.'
                );
            }

            return;
        }

        if ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$teacherProfile ||
                (int) $course->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized course access.'
                );
            }

            return;
        }

        abort(
            403,
            'Only staff may manage enrollments.'
        );
    }
}
