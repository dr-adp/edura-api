<?php

namespace App\Http\Controllers\Api;

use App\Services\CourseEnrollmentService;
use App\Services\CourseAssessmentLockService;
use App\Exceptions\AssessmentLockSetChanged;
use App\Models\CourseEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
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
        CourseEnrollmentService $service,
        private CourseAssessmentLockService $courseAssessmentLockService
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

        if (!$user->hasRole('super-admin')) {
            $query->institutionallyConsistent();
        }

        if ($user->hasRole('super-admin')) {

            // Full access

        } elseif ($user->hasRole('institution-admin')) {

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
                'course',
                fn($q) => $q->where(
                    'institution_id',
                    $institutionUser->institution_id
                )
            )->whereHas(
                'studentProfile',
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

        $enrollment = $this->service->create(
            $validated,
            fn (Course $course, StudentProfile $studentProfile) =>
                $this->authorizeCourseEnrollmentManagement($course, $studentProfile)
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
        $this->authorizeCourseEnrollmentAccess(
            $courseEnrollment
        );

        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validated();
        $updatedEnrollment = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use (
                $courseEnrollment,
                $validated,
                $user
            ): CourseEnrollment {
                $context = $this->lockEnrollmentMutationContext(
                    (int) $courseEnrollment->id,
                    $validated['course_id'] ?? null,
                    $validated['student_profile_id'] ?? null
                );
                /** @var CourseEnrollment $lockedEnrollment */
                $lockedEnrollment = $context['enrollment'];

                $this->authorizeCourseEnrollmentAccess($lockedEnrollment);
                $this->authorizeCourseEnrollmentManagement(
                    $context['target_course'],
                    $context['target_student']
                );

                if ($user->hasRole('student')) {
                    abort(403, 'Students cannot modify enrollments.');
                }

                if ($user->hasRole('parent')) {
                    abort(403, 'Parents cannot modify enrollments.');
                }

                $attributes = $validated;

                if (
                    isset($attributes['progress_percentage']) &&
                    (float) $attributes['progress_percentage'] >= 100
                ) {
                    $attributes['progress_percentage'] = 100;
                    $attributes['status'] = 'completed';
                    $attributes['completed_at'] = now();
                }

                if (
                    ($attributes['status'] ?? null) === 'completed' &&
                    empty($attributes['completed_at'])
                ) {
                    $attributes['completed_at'] = now();
                }

                if (
                    isset($attributes['amount_paid']) &&
                    $attributes['amount_paid'] > 0 &&
                    ($attributes['payment_status'] ?? $lockedEnrollment->payment_status) === 'free'
                ) {
                    abort(422, 'Free enrollment cannot contain paid amount.');
                }

                $lockedEnrollment->fill($attributes);
                $lockedEnrollment->save();
                $this->service->recalculateProgressWithinTransaction($lockedEnrollment);

                return $lockedEnrollment->fresh();
            });

        return $this->successResponse(
            $updatedEnrollment->load([
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

        $this->courseAssessmentLockService->transactionWithLockSetRetries(
            function () use ($courseEnrollment, $user): void {
                $context = $this->lockEnrollmentMutationContext(
                    (int) $courseEnrollment->id
                );
                /** @var CourseEnrollment $lockedEnrollment */
                $lockedEnrollment = $context['enrollment'];

                $this->authorizeCourseEnrollmentAccess($lockedEnrollment);

                if ($user->hasRole('parent')) {
                    abort(403, 'Parents cannot delete enrollments.');
                }

                if ($user->hasRole('student')) {
                    abort(403, 'Students cannot delete enrollments.');
                }

                if ($lockedEnrollment->status === 'completed') {
                    abort(422, 'Completed enrollments cannot be deleted.');
                }

                $lockedEnrollment->delete();
            }
        );

        return $this->successResponse(
            null,
            'Course enrollment deleted successfully.'
        );
    }

    /**
     * Lock old and requested parent pairs before locking the enrollment row.
     * A changed pair escapes the transaction so the complete operation can
     * retry with a fresh parent set.
     *
     * @return array{
     *     enrollment:CourseEnrollment,
     *     target_course:Course,
     *     target_student:StudentProfile
     * }
     */
    private function lockEnrollmentMutationContext(
        int $enrollmentId,
        ?int $requestedCourseId = null,
        ?int $requestedStudentProfileId = null
    ): array {
        $identity = CourseEnrollment::query()
            ->whereKey($enrollmentId)
            ->firstOrFail();
        $oldCourseId = (int) $identity->course_id;
        $oldStudentProfileId = (int) $identity->student_profile_id;
        $targetCourseId = $requestedCourseId ?? $oldCourseId;
        $targetStudentProfileId = $requestedStudentProfileId ?? $oldStudentProfileId;

        $courseIds = array_values(array_unique([$oldCourseId, $targetCourseId]));
        $studentProfileIds = array_values(array_unique([
            $oldStudentProfileId,
            $targetStudentProfileId,
        ]));
        sort($courseIds, SORT_NUMERIC);
        sort($studentProfileIds, SORT_NUMERIC);

        $lockedCourses = collect();
        foreach ($courseIds as $courseId) {
            $lockedCourses->put(
                $courseId,
                $this->courseAssessmentLockService->lockCourse(
                    $courseId,
                    withTrashed: false
                )
            );
        }

        $lockedStudents = collect();
        foreach ($studentProfileIds as $studentProfileId) {
            $lockedStudents->put(
                $studentProfileId,
                $this->courseAssessmentLockService->lockStudent($studentProfileId)
            );
        }

        $pairs = [
            [
                'course_id' => $oldCourseId,
                'student_profile_id' => $oldStudentProfileId,
            ],
            [
                'course_id' => $targetCourseId,
                'student_profile_id' => $targetStudentProfileId,
            ],
        ];
        $lockedPairs = $this->courseAssessmentLockService
            ->lockCourseEnrollments($pairs);
        $enrollment = $lockedPairs->first(
            fn (CourseEnrollment $candidate): bool =>
                (int) $candidate->id === $enrollmentId
        );

        if (! $enrollment) {
            $currentEnrollment = CourseEnrollment::withTrashed()
                ->whereKey($enrollmentId)
                ->first();

            if (! $currentEnrollment) {
                abort(404);
            }

            throw new AssessmentLockSetChanged(
                array_merge($courseIds, [(int) $currentEnrollment->course_id]),
                array_merge($studentProfileIds, [(int) $currentEnrollment->student_profile_id])
            );
        }

        if (
            $enrollment->trashed() ||
            (int) $enrollment->course_id !== $oldCourseId ||
            (int) $enrollment->student_profile_id !== $oldStudentProfileId
        ) {
            throw new AssessmentLockSetChanged(
                array_merge($courseIds, [(int) $enrollment->course_id]),
                array_merge($studentProfileIds, [(int) $enrollment->student_profile_id])
            );
        }

        $targetEnrollment = $lockedPairs->first(
            fn (CourseEnrollment $lockedPair): bool =>
                (int) $lockedPair->course_id === $targetCourseId &&
                (int) $lockedPair->student_profile_id === $targetStudentProfileId
        );

        if ($targetEnrollment && (int) $targetEnrollment->id !== $enrollmentId) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'The student is already enrolled in this course.',
            ]);
        }

        $oldCourse = $lockedCourses->get($oldCourseId);
        $oldStudent = $lockedStudents->get($oldStudentProfileId);
        $targetCourse = $lockedCourses->get($targetCourseId);
        $targetStudent = $lockedStudents->get($targetStudentProfileId);

        $enrollment->setRelation('course', $oldCourse);
        $enrollment->setRelation('studentProfile', $oldStudent);

        return [
            'enrollment' => $enrollment,
            'target_course' => $targetCourse,
            'target_student' => $targetStudent,
        ];
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

        if (!$enrollment->hasConsistentInstitutions()) {
            abort(403, 'Course and student must belong to the same institution.');
        }

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )
                ->where('status', 'active')
                ->first();

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

        $this->service->ensureCourseAndStudentShareInstitution(
            $course,
            $studentProfile
        );

        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )
                ->where('status', 'active')
                ->first();

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
