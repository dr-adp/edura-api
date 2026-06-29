<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\DB;
use App\Services\CourseEnrollmentService;
use App\Models\CourseEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Http\Requests\StoreCourseEnrollmentRequest;
use App\Http\Requests\UpdateCourseEnrollmentRequest;

class CourseEnrollmentController extends BaseApiController
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

        return $this->successResponse(
            $enrollments,
            'Course enrollments fetched successfully.'
        );
    }

    public function store(StoreCourseEnrollmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

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

        return $this->successResponse(
            $enrollment->load([
                'course',
                'studentProfile.user',
                'studentProfile.institution',
                'studentProfile.batch',
            ]),
            'Student enrolled in course successfully.',
            201
        );
    }

    public function show(
        CourseEnrollment $courseEnrollment
    ): JsonResponse {

        $this->authorizeCourseEnrollmentAccess(
            $courseEnrollment
        );

        return $this->successResponse(
            $courseEnrollment->load([
                'course',
                'studentProfile.user',
                'studentProfile.institution',
                'studentProfile.batch',
            ]),
            'Course enrollment fetched successfully.'
        );
    }

    public function update(
        UpdateCourseEnrollmentRequest $request,
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
        $validated = $request->validated();

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

        return $this->successResponse(
            $courseEnrollment
                ->fresh()
                ->load([
                    'course',
                    'studentProfile.user',
                    'studentProfile.institution',
                    'studentProfile.batch',
                ]),
            'Course enrollment updated successfully.'
        );
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

        return $this->successResponse(
            null,
            'Course enrollment deleted successfully.'
        );
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
