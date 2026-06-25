<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\InstitutionUser;
use App\Models\LiveClass;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LiveClassController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = LiveClass::with([
            'course',
            'teacherProfile.user',
        ]);

        /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('super-admin')) {

            // Full access

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

            $query->where(
                'teacher_profile_id',
                $teacherProfile->id
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Student / Parent
    |--------------------------------------------------------------------------
    */ else {

            abort(
                403,
                'Unauthorized.'
            );
        }

        $liveClasses = $query
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Live classes fetched successfully.',
            'data' => $liveClasses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'course_id' => ['required', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],

            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'platform' => ['nullable', 'in:google_meet,zoom,jitsi,microsoft_teams,other'],
            'meeting_url' => ['required', 'string', 'max:1000'],
            'meeting_id' => ['nullable', 'string', 'max:255'],
            'meeting_password' => ['nullable', 'string', 'max:255'],

            'scheduled_start_time' => ['required', 'date'],
            'scheduled_end_time' => ['nullable', 'date', 'after:scheduled_start_time'],

            'recording_url' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:scheduled,live,completed,cancelled'],
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
    | Institution Integrity
    |--------------------------------------------------------------------------
    */
        $validated['institution_id'] = $course->institution_id;

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        if (!$user->hasRole('super-admin')) {

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
            } elseif ($user->hasRole('teacher')) {

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
                        'Unauthorized teacher access.'
                    );
                }

                $validated['teacher_profile_id'] =
                    $teacherProfile->id;
            } else {

                abort(
                    403,
                    'Unauthorized.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */
        $liveClass = LiveClass::create(
            $validated
        );

        return response()->json([
            'message' => 'Live class created successfully.',
            'data' => $liveClass->load([
                'institution',
                'course',
                'courseSection',
                'lesson',
                'teacherProfile.user',
                'batch',
            ]),
        ], 201);
    }

    public function show(
        LiveClass $liveClass
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLiveClassAccess(
            $liveClass
        );

        return response()->json([
            'message' => 'Live class fetched successfully.',
            'data' => $liveClass->load([
                'course',
                'teacherProfile.user',
            ]),
        ]);
    }

    public function update(
        Request $request,
        LiveClass $liveClass
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLiveClassManagement(
            $liveClass
        );

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'course_id' => ['sometimes', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],

            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'platform' => ['nullable', 'in:google_meet,zoom,jitsi,microsoft_teams,other'],
            'meeting_url' => ['sometimes', 'string', 'max:1000'],
            'meeting_id' => ['nullable', 'string', 'max:255'],
            'meeting_password' => ['nullable', 'string', 'max:255'],

            'scheduled_start_time' => ['sometimes', 'date'],
            'scheduled_end_time' => ['nullable', 'date', 'after:scheduled_start_time'],

            'recording_url' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:scheduled,live,completed,cancelled'],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Course Integrity
    |--------------------------------------------------------------------------
    */
        if (isset($validated['course_id'])) {

            $course = Course::findOrFail(
                $validated['course_id']
            );

            $validated['institution_id'] =
                $course->institution_id;

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
                        'Unauthorized teacher access.'
                    );
                }

                $validated['teacher_profile_id'] =
                    $teacherProfile->id;
            }
        }

        $liveClass->update(
            $validated
        );

        return response()->json([
            'message' => 'Live class updated successfully.',
            'data' => $liveClass
                ->fresh()
                ->load([
                    'institution',
                    'course',
                    'courseSection',
                    'lesson',
                    'teacherProfile.user',
                    'batch',
                ]),
        ]);
    }

    public function destroy(
        LiveClass $liveClass
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLiveClassManagement(
            $liveClass
        );

        /*
    |--------------------------------------------------------------------------
    | Prevent Deleting Live Classes With Attendance
    |--------------------------------------------------------------------------
    */
        if (
            $liveClass->attendances()->exists()
        ) {

            abort(
                422,
                'Live class cannot be deleted because attendance records exist.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Soft Delete
    |--------------------------------------------------------------------------
    */
        $liveClass->delete();

        return response()->json([
            'message' => 'Live class deleted successfully.',
        ]);
    }

    private function authorizeLiveClassAccess(
        LiveClass $liveClass
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
                (int) $liveClass->institution_id !==
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
                (int) $liveClass->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized live class.'
                );
            }

            return;
        }

        if (
            $user->hasAnyRole([
                'student',
                'parent'
            ])
        ) {

            abort(
                403,
                'Unauthorized.'
            );
        }

        abort(
            403,
            'Unauthorized.'
        );
    }

    private function authorizeLiveClassManagement(
        LiveClass $liveClass
    ): void {

        $this->authorizeLiveClassAccess(
            $liveClass
        );
    }
}
