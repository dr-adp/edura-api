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

        // Teacher: only own assignments
        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {
                abort(403, 'Teacher profile not found.');
            }

            $query->where(
                'teacher_profile_id',
                $teacherProfile->id
            );
        }

        $assignments = $query
            ->latest()
            ->paginate(20);

        return $this->successResponse(
            $assignments,
            'Assignments fetched successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'maximum_marks' => ['nullable', 'numeric', 'min:0'],
            'available_from' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'allow_late_submission' => ['boolean'],
            'status' => ['nullable', 'in:draft,published,closed'],
        ]);

        $course = Course::findOrFail(
            $validated['course_id']
        );

        // Institution Admin
        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                $course->institution_id !== $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized: Course does not belong to your institution.'
                );
            }
        }

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
        Request $request,
        Assignment $assignment
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (
                !$teacherProfile ||
                $assignment->teacher_profile_id !== $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized.'
                );
            }
        }

        $validated = $request->validate([
            'course_id' => ['sometimes', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'maximum_marks' => ['nullable', 'numeric', 'min:0'],
            'available_from' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'allow_late_submission' => ['boolean'],
            'status' => ['nullable', 'in:draft,published,closed'],
        ]);

        $assignment->update($validated);

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

        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (
                !$teacherProfile ||
                $assignment->teacher_profile_id !== $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized.'
                );
            }
        }

        $assignment->delete();

        return $this->successResponse(
            null,
            'Assignment deleted successfully.'
        );
    }
}
