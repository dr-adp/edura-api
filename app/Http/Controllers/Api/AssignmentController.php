<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AssessmentLockSetChanged;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;

class AssignmentController extends BaseApiController
{
    public function __construct(
        private CourseAssessmentLockService $courseAssessmentLockService
    ) {
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = Assignment::with([
            'course',
            'courseSection',
            'lesson',
            'teacherProfile.user'
        ]);

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            $query->whereHas('course', function ($courseQuery) use ($institutionUser) {
                $courseQuery->where('institution_id', $institutionUser->institution_id);
            });
        } elseif ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {
                abort(403, 'Teacher profile not found.');
            }

            $query->where(
                'teacher_profile_id',
                $teacherProfile->id
            );
        } elseif (!$user->hasRole('super-admin')) {
            abort(403, 'Unauthorized role.');
        }

        $assignments = $query
            ->latest()
            ->paginate(20);

        return $this->successResponse(
            $assignments,
            'Assignments fetched successfully.'
        );
    }

    public function store(StoreAssignmentRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validated();
        $assignment = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($validated, $user): Assignment {
                $course = $this->courseAssessmentLockService->lockCourse(
                    (int) $validated['course_id'],
                    withTrashed: false
                );
                $this->authorizeCourseAccess($course, $user);

                $attributes = $validated;

                if ($user->hasRole('teacher')) {
                    $teacherProfile = $user->teacherProfile;

                    if (
                        ! $teacherProfile ||
                        (int) $course->teacher_profile_id !== (int) $teacherProfile->id
                    ) {
                        abort(
                            403,
                            'Unauthorized: You can create assignments only for your courses.'
                        );
                    }

                    $attributes['teacher_profile_id'] = $teacherProfile->id;
                }

                if ($user->hasAnyRole(['student', 'parent'])) {
                    abort(403, 'Unauthorized.');
                }

                $this->validateAssignmentCourseRelationships(
                    $course,
                    $attributes['lesson_id'] ?? null,
                    $attributes['course_section_id'] ?? null
                );

                return Assignment::create($attributes);
            });

        return $this->successResponse(
            $assignment->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
            'Assignment created successfully.',
            201
        );
    }

    public function show(Assignment $assignment): JsonResponse
    {
        $this->authorizeAssignmentAccess($assignment, Auth::user());

        return $this->successResponse(
            $assignment->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
            'Assignment fetched successfully.'
        );
    }

    public function update(
        UpdateAssignmentRequest $request,
        Assignment $assignment
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        $this->authorizeAssignmentAccess($assignment, $user);

        $validated = $request->validated();
        $assignmentId = (int) $assignment->id;
        $updatedAssignment = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use (
                $assignmentId,
                $validated,
                $user
            ): Assignment {
                $identity = Assignment::query()->whereKey($assignmentId)->firstOrFail();
                $oldCourseId = (int) $identity->course_id;
                $targetCourseId = (int) ($validated['course_id'] ?? $oldCourseId);
                $courseIds = array_values(array_unique([$oldCourseId, $targetCourseId]));
                sort($courseIds, SORT_NUMERIC);

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

                $lockedAssignment = $this->courseAssessmentLockService
                    ->lockAssignments([$assignmentId])
                    ->first();

                if (! $lockedAssignment || $lockedAssignment->trashed()) {
                    abort(404);
                }

                if ((int) $lockedAssignment->course_id !== $oldCourseId) {
                    throw new AssessmentLockSetChanged(
                        array_merge($courseIds, [(int) $lockedAssignment->course_id])
                    );
                }

                $lockedAssignment->setRelation('course', $lockedCourses->get($oldCourseId));
                $this->authorizeAssignmentAccess($lockedAssignment, $user);
                $targetCourse = $lockedCourses->get($targetCourseId);
                $this->authorizeCourseAccess($targetCourse, $user);

                $this->validateAssignmentCourseRelationships(
                    $targetCourse,
                    array_key_exists('lesson_id', $validated)
                        ? $validated['lesson_id']
                        : $lockedAssignment->lesson_id,
                    array_key_exists('course_section_id', $validated)
                        ? $validated['course_section_id']
                        : $lockedAssignment->course_section_id
                );

                $lockedAssignment->fill($validated);
                $lockedAssignment->save();

                return $lockedAssignment;
            });

        return $this->successResponse(
            $updatedAssignment->fresh()->load([
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user'
            ]),
            'Assignment updated successfully.'
        );
    }

    public function destroy(
        Assignment $assignment
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        $this->authorizeAssignmentAccess($assignment, $user);
        $assignmentId = (int) $assignment->id;

        $this->courseAssessmentLockService->transactionWithLockSetRetries(
            function () use ($assignmentId, $user): void {
                $identity = Assignment::query()->whereKey($assignmentId)->firstOrFail();
                $courseId = (int) $identity->course_id;
                $course = $this->courseAssessmentLockService->lockCourse(
                    $courseId,
                    withTrashed: false
                );
                $lockedAssignment = $this->courseAssessmentLockService
                    ->lockAssignments([$assignmentId])
                    ->first();

                if (! $lockedAssignment || $lockedAssignment->trashed()) {
                    abort(404);
                }

                if ((int) $lockedAssignment->course_id !== $courseId) {
                    throw new AssessmentLockSetChanged(
                        [$courseId, (int) $lockedAssignment->course_id]
                    );
                }

                $lockedAssignment->setRelation('course', $course);
                $this->authorizeAssignmentAccess($lockedAssignment, $user);

                // Soft deletion preserves submission and evaluation history.
                $lockedAssignment->delete();
            }
        );

        return $this->successResponse(
            null,
            'Assignment deleted successfully.'
        );
    }

    private function validateAssignmentCourseRelationships(
        Course $course,
        mixed $lessonId,
        mixed $courseSectionId
    ): void {
        $errors = [];

        if (
            $lessonId !== null &&
            ! DB::table('lessons')
                ->where('id', $lessonId)
                ->where('course_id', $course->id)
                ->exists()
        ) {
            $errors['lesson_id'] = 'The selected lesson must belong to the assignment course.';
        }

        if (
            $courseSectionId !== null &&
            ! DB::table('course_sections')
                ->where('id', $courseSectionId)
                ->where('course_id', $course->id)
                ->exists()
        ) {
            $errors['course_section_id'] = 'The selected course section must belong to the assignment course.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function authorizeAssignmentAccess(Assignment $assignment, User $user): void
    {
        $assignment->loadMissing('course');

        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            if (
                $assignment->course &&
                (int) $assignment->course->institution_id ===
                    (int) $institutionUser->institution_id
            ) {
                return;
            }

            abort(403, 'Unauthorized institution access.');
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = $user->teacherProfile;

            if (
                $teacherProfile &&
                (int) $assignment->teacher_profile_id === (int) $teacherProfile->id
            ) {
                return;
            }

            abort(403, 'Unauthorized assignment access.');
        }

        abort(403, 'Unauthorized role.');
    }

    private function authorizeCourseAccess(Course $course, User $user): void
    {
        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            if ((int) $course->institution_id === (int) $institutionUser->institution_id) {
                return;
            }

            abort(403, 'Unauthorized: Course does not belong to your institution.');
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = $user->teacherProfile;

            if (
                $teacherProfile &&
                (int) $course->teacher_profile_id === (int) $teacherProfile->id
            ) {
                return;
            }

            abort(403, 'Unauthorized: You can access assignments only for your courses.');
        }

        abort(403, 'Unauthorized role.');
    }

    private function institutionUserFor(User $user): InstitutionUser
    {
        $institutionUser = InstitutionUser::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($institutionUser) {
            return $institutionUser;
        }

        abort(403, 'Active institution membership required.');
    }
}
