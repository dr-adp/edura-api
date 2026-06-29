<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\InstitutionUser;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;

class CourseController extends BaseApiController
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = Course::with([
            'institution',
            'department',
            'batch',
            'teacherProfile.user'
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

            $query->where(
                'institution_id',
                $institutionUser->institution_id
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

            $query->where(
                'teacher_profile_id',
                $teacherProfile->id
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

        $courses = $query
            ->latest()
            ->paginate(10);

        return $this->successResponse(
            $courses,
            'Courses fetched successfully.'
        );
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validated();

        /*
    |--------------------------------------------------------------------------
    | Institution Admin Restrictions
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

            $validated['institution_id'] =
                $institutionUser->institution_id;
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher Restrictions
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

            $validated['teacher_profile_id'] =
                $teacherProfile->id;

            $validated['institution_id'] =
                $teacherProfile->institution_id;
        }

        /*
    |--------------------------------------------------------------------------
    | Authorization Check
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseAccess(
            institutionId: $validated['institution_id'] ?? null,
            teacherProfileId: $validated['teacher_profile_id'] ?? null
        );

        /*
    |--------------------------------------------------------------------------
    | Slug Generation
    |--------------------------------------------------------------------------
    */
        $validated['slug'] =
            Str::slug($validated['title'])
            . '-' . time();

        $course = Course::create(
            $validated
        );

        return $this->successResponse(
            $course->load([
                'institution',
                'department',
                'batch',
                'teacherProfile.user'
            ]),
            'Course created successfully.',
            201
        );
    }

    public function show(Course $course): JsonResponse
    {
        $this->authorizeCourseAccess(
            course: $course
        );

        return $this->successResponse(
            $course->load([
                'institution',
                'department',
                'batch',
                'teacherProfile.user'
            ]),
            'Course fetched successfully.'
        );
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Existing Course Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseAccess(
            course: $course
        );

        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validated();

        /*
    |--------------------------------------------------------------------------
    | Institution Admin Restrictions
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

            $validated['institution_id'] =
                $institutionUser->institution_id;
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher Restrictions
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

            /*
        | Prevent ownership transfer
        */
            $validated['teacher_profile_id'] =
                $teacherProfile->id;

            /*
        | Prevent institution switching
        */
            $validated['institution_id'] =
                $teacherProfile->institution_id;
        }

        /*
    |--------------------------------------------------------------------------
    | Re-Authorization Of Target Data
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseAccess(
            institutionId: $validated['institution_id']
                ?? $course->institution_id,

            teacherProfileId: $validated['teacher_profile_id']
                ?? $course->teacher_profile_id
        );

        /*
    |--------------------------------------------------------------------------
    | Slug Update
    |--------------------------------------------------------------------------
    */
        if (isset($validated['title'])) {

            $validated['slug'] =
                Str::slug($validated['title'])
                . '-' . time();
        }

        $course->update(
            $validated
        );

        return $this->successResponse(
            $course
                ->fresh()
                ->load([
                    'institution',
                    'department',
                    'batch',
                    'teacherProfile.user'
                ]),
            'Course updated successfully.'
        );
    }

    public function destroy(Course $course): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseAccess(
            course: $course
        );

        $course->delete();

        return $this->successResponse(
            null,
            'Course deleted successfully.'
        );
    }

    private function authorizeCourseAccess(
        ?Course $course = null,
        ?int $institutionId = null,
        ?int $teacherProfileId = null
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

            $targetInstitutionId =
                $course?->institution_id
                ?? $institutionId;

            if (
                !$targetInstitutionId ||
                (int)$targetInstitutionId !==
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

            if ($course) {

                if (
                    (int)$course->teacher_profile_id !==
                    (int)$teacherProfile->id
                ) {

                    abort(
                        403,
                        'Unauthorized course access.'
                    );
                }

                return;
            }

            if (
                $teacherProfileId &&
                (int)$teacherProfileId !==
                (int)$teacherProfile->id
            ) {

                abort(
                    403,
                    'You can only manage your own courses.'
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
