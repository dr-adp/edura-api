<?php

namespace App\Http\Controllers\Api;

use App\Models\CourseSection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class CourseSectionController extends Controller
{
    private function authorizeCourseSectionAccess(
        CourseSection $courseSection
    ): void {

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        $courseSection->loadMissing('course');

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                (int) $courseSection->course->institution_id !==
                (int) $institutionUser->institution_id
            ) {
                abort(403, 'Unauthorized institution access.');
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
                (int) $courseSection->course->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {
                abort(403, 'Unauthorized course section.');
            }

            return;
        }

        abort(403, 'Unauthorized.');
    }
    private function authorizeCourseSectionManagement(
        Course $course
    ): void {

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                (int) $course->institution_id !==
                (int) $institutionUser->institution_id
            ) {
                abort(403, 'Unauthorized institution access.');
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
                abort(403, 'Unauthorized course.');
            }

            return;
        }

        abort(403, 'Unauthorized.');
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = CourseSection::with([
            'course',
        ]);

        if ($user->hasRole('super-admin')) {

            // Full access

        } elseif ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (!$institutionUser) {
                abort(403, 'Institution profile not found.');
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
        } elseif ($user->hasRole('teacher')) {

            $teacherProfile = TeacherProfile::where(
                'user_id',
                $user->id
            )->first();

            if (!$teacherProfile) {
                abort(403, 'Teacher profile not found.');
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
        } else {

            abort(403, 'Unauthorized.');
        }

        return response()->json([
            'message' => 'Course sections fetched successfully.',
            'data' => $query
                ->orderBy('sort_order')
                ->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Load Course
    |--------------------------------------------------------------------------
    */
        $course = Course::findOrFail(
            $validated['course_id']
        );

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseSectionManagement(
            $course
        );

        /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */
        $courseSection = CourseSection::create(
            $validated
        );

        return response()->json([
            'message' => 'Course section created successfully.',
            'data' => $courseSection->load([
                'course',
            ]),
        ], 201);
    }

    public function show(
        CourseSection $courseSection
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseSectionAccess(
            $courseSection
        );

        return response()->json([
            'message' => 'Course section fetched successfully.',
            'data' => $courseSection->load([
                'course',
            ]),
        ]);
    }

    public function update(
        Request $request,
        CourseSection $courseSection
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseSectionAccess(
            $courseSection
        );

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'course_id' => ['sometimes', 'exists:courses,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Course Change Authorization
    |--------------------------------------------------------------------------
    */
        if (isset($validated['course_id'])) {

            $course = Course::findOrFail(
                $validated['course_id']
            );

            $this->authorizeCourseSectionManagement(
                $course
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */
        $courseSection->update(
            $validated
        );

        return response()->json([
            'message' => 'Course section updated successfully.',
            'data' => $courseSection
                ->fresh()
                ->load([
                    'course',
                ]),
        ]);
    }

    public function destroy(
        CourseSection $courseSection
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeCourseSectionAccess(
            $courseSection
        );

        /*
    |--------------------------------------------------------------------------
    | Prevent Delete When Lessons Exist
    |--------------------------------------------------------------------------
    */
        if (
            $courseSection->lessons()->exists()
        ) {

            abort(
                422,
                'Course section cannot be deleted because it contains lessons.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Soft Delete
    |--------------------------------------------------------------------------
    */
        $courseSection->delete();

        return response()->json([
            'message' => 'Course section deleted successfully.',
        ]);
    }
}
