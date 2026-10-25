<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;

class AssignmentController extends BaseApiController
{
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

        $course = Course::findOrFail(
            $validated['course_id']
        );

        $this->authorizeCourseAccess($course, $user);

        // Teacher
        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (
                !$teacherProfile ||
                $course->teacher_profile_id !== $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized: You can create assignments only for your courses.'
                );
            }

            $validated['teacher_profile_id'] = $teacherProfile->id;
        }

        // Student / Parent
        if (
            $user->hasAnyRole([
                'student',
                'parent'
            ])
        ) {

            abort(403, 'Unauthorized.');
        }

        $assignment = Assignment::create($validated);

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

        $targetCourse = Course::findOrFail(
            $validated['course_id'] ?? $assignment->course_id
        );
        $this->authorizeCourseAccess($targetCourse, $user);

        $assignment->fill($validated);
        $assignment->save();

        return $this->successResponse(
            $assignment->fresh()->load([
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

        $assignment->delete();

        return $this->successResponse(
            null,
            'Assignment deleted successfully.'
        );
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
