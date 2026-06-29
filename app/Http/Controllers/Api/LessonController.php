<?php

namespace App\Http\Controllers\Api;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use App\Models\Course;
use App\Models\InstitutionUser;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\StoreLessonRequest;
use App\Http\Requests\UpdateLessonRequest;

class LessonController extends BaseApiController
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = Lesson::with([
            'course',
            'courseSection'
        ]);

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

            $query->whereHas(
                'course',
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
    | Teacher
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            $query->whereHas(
                'course',
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
    | Super Admin
    |--------------------------------------------------------------------------
    */ elseif (!$user->hasRole('super-admin')) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        $lessons = $query
            ->orderBy('sort_order')
            ->paginate(20);

        return $this->successResponse(
            $lessons,
            'Lessons fetched successfully.'
        );
    }

    public function store(StoreLessonRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /*
    |--------------------------------------------------------------------------
    | Course Ownership Validation
    |--------------------------------------------------------------------------
    */
        $course = Course::findOrFail(
            $validated['course_id']
        );

        $this->authorizeLessonAccess(
            course: $course
        );

        /*
    |--------------------------------------------------------------------------
    | Course Section Validation
    |--------------------------------------------------------------------------
    */
        if (!empty($validated['course_section_id'])) {

            $section = $course->sections()
                ->where(
                    'id',
                    $validated['course_section_id']
                )
                ->first();

            if (!$section) {

                abort(
                    422,
                    'Selected course section does not belong to the course.'
                );
            }
        }

        $lesson = Lesson::create(
            $validated
        );

        return $this->successResponse(
            $lesson->load([
                'course',
                'courseSection'
            ]),
            'Lesson created successfully.',
            201
        );
    }

    public function show(Lesson $lesson): JsonResponse
    {
        $this->authorizeLessonAccess(
            lesson: $lesson
        );

        return $this->successResponse(
            $lesson->load([
                'course',
                'courseSection'
            ]),
            'Lesson fetched successfully.'
        );
    }

    public function update(UpdateLessonRequest $request, Lesson $lesson): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Existing Lesson Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLessonAccess(
            lesson: $lesson
        );

        $validated = $request->validated();

        /*
    |--------------------------------------------------------------------------
    | Target Course Validation
    |--------------------------------------------------------------------------
    */
        $course = isset($validated['course_id'])
            ? Course::findOrFail($validated['course_id'])
            : $lesson->course;

        $this->authorizeLessonAccess(
            course: $course
        );

        /*
    |--------------------------------------------------------------------------
    | Course Section Validation
    |--------------------------------------------------------------------------
    */
        if (
            array_key_exists('course_section_id', $validated)
            && !empty($validated['course_section_id'])
        ) {

            $section = $course->sections()
                ->where(
                    'id',
                    $validated['course_section_id']
                )
                ->first();

            if (!$section) {

                abort(
                    422,
                    'Selected course section does not belong to the course.'
                );
            }
        }

        $lesson->update(
            $validated
        );

        return $this->successResponse(
            $lesson
                ->fresh()
                ->load([
                    'course',
                    'courseSection'
                ]),
            'Lesson updated successfully.'
        );
    }

    public function destroy(Lesson $lesson): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLessonAccess(
            lesson: $lesson
        );

        $lesson->delete();

        return $this->successResponse(
            null,
            'Lesson deleted successfully.'
        );
    }

    private function authorizeLessonAccess(
        ?Lesson $lesson = null,
        ?Course $course = null
    ): void {
        /** @var User $user */
        $user = Auth::user();

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

            if ($lesson) {
                $lesson->loadMissing('course');
            }

            $institutionId =
                $lesson?->course?->institution_id
                ?? $course?->institution_id;

            if (
                !$institutionId ||
                (int)$institutionId !==
                (int)$institutionUser->institution_id
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

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Teacher profile not found.'
                );
            }

            if ($lesson) {
                $lesson->loadMissing('course');
            }

            $teacherId =
                $lesson?->course?->teacher_profile_id
                ?? $course?->teacher_profile_id;

            if (
                !$teacherId ||
                (int)$teacherId !==
                (int)$teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized lesson access.'
                );
            }

            return;
        }

        abort(
            403,
            'Unauthorized role.'
        );
    }
}
