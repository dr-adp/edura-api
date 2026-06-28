<?php

namespace App\Http\Controllers\Api;

use App\Models\LiveClassAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\LiveClass;
use App\Models\InstitutionUser;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class LiveClassAttendanceController extends BaseApiController
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = LiveClassAttendance::with([
            'liveClass',
            'studentProfile.user',
            'studentProfile.institution',
            'studentProfile.batch',
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

            $query->whereHas(
                'liveClass',
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
                'liveClass',
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
    | Student
    |--------------------------------------------------------------------------
    */ elseif ($user->hasRole('student')) {

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
        }

        /*
    |--------------------------------------------------------------------------
    | Parent / Others
    |--------------------------------------------------------------------------
    */ else {

            abort(
                403,
                'Unauthorized.'
            );
        }

        $attendance = $query
            ->latest()
            ->paginate(20);

        return $this->successResponse(
            $attendance,
            'Live class attendance fetched successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'live_class_id' => [
                'required',
                'exists:live_classes,id',
            ],

            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
                Rule::unique(
                    'live_class_attendances'
                )->where(function ($query) use ($request) {

                    return $query->where(
                        'live_class_id',
                        $request->live_class_id
                    )->where(
                        'student_profile_id',
                        $request->student_profile_id
                    );
                }),
            ],

            'attendance_status' => [
                'required',
                'in:present,absent,late'
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Load Records
    |--------------------------------------------------------------------------
    */
        $liveClass = LiveClass::findOrFail(
            $validated['live_class_id']
        );

        $studentProfile = StudentProfile::findOrFail(
            $validated['student_profile_id']
        );

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLiveClassAttendanceManagement(
            $liveClass,
            $studentProfile
        );

        /*
    |--------------------------------------------------------------------------
    | Prevent Attendance For Cancelled Classes
    |--------------------------------------------------------------------------
    */
        if (
            isset($liveClass->status) &&
            $liveClass->status === 'cancelled'
        ) {

            abort(
                422,
                'Attendance cannot be recorded for a cancelled live class.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Create Attendance
    |--------------------------------------------------------------------------
    */
        $attendance = LiveClassAttendance::create(
            $validated
        );

        return $this->successResponse(
            $attendance->load([
                'liveClass',
                'studentProfile.user',
                'studentProfile.institution',
                'studentProfile.batch',
            ]),
            'Live class attendance recorded successfully.',
            201
        );
    }

    public function show(
        LiveClassAttendance $liveClassAttendance
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLiveClassAttendanceAccess(
            $liveClassAttendance
        );

        return $this->successResponse(
            $liveClassAttendance->load([
                'liveClass',
                'studentProfile.user',
                'studentProfile.institution',
                'studentProfile.batch',
            ]),
            'Live class attendance fetched successfully.'
        );
    }

    public function update(
        Request $request,
        LiveClassAttendance $liveClassAttendance
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLiveClassAttendanceAccess(
            $liveClassAttendance
        );

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validate([
            'attendance_status' => [
                'sometimes',
                'in:present,absent,late',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Prevent Updating Cancelled Live Classes
    |--------------------------------------------------------------------------
    */
        $liveClassAttendance->loadMissing(
            'liveClass'
        );

        if (
            isset($liveClassAttendance->liveClass->status) &&
            $liveClassAttendance->liveClass->status === 'cancelled'
        ) {

            abort(
                422,
                'Attendance cannot be modified for a cancelled live class.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Update Attendance
    |--------------------------------------------------------------------------
    */
        $liveClassAttendance->update(
            $validated
        );

        return $this->successResponse(
            $liveClassAttendance
                ->fresh()
                ->load([
                    'liveClass',
                    'studentProfile.user',
                    'studentProfile.institution',
                    'studentProfile.batch',
                ]),
            'Live class attendance updated successfully.'
        );
    }

    public function destroy(
        LiveClassAttendance $liveClassAttendance
    ): JsonResponse {

        /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
        $this->authorizeLiveClassAttendanceAccess(
            $liveClassAttendance
        );

        /*
    |--------------------------------------------------------------------------
    | Prevent Deleting Attendance For Cancelled Live Classes
    |--------------------------------------------------------------------------
    */
        $liveClassAttendance->loadMissing(
            'liveClass'
        );

        if (
            isset($liveClassAttendance->liveClass->status) &&
            $liveClassAttendance->liveClass->status === 'cancelled'
        ) {

            abort(
                422,
                'Attendance cannot be deleted for a cancelled live class.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Soft Delete
    |--------------------------------------------------------------------------
    */
        $liveClassAttendance->delete();

        return $this->successResponse(
            null,
            'Live class attendance deleted successfully.'
        );
    }

    private function authorizeLiveClassAttendanceAccess(
        LiveClassAttendance $attendance
    ): void {

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        $attendance->loadMissing([
            'liveClass',
            'studentProfile',
        ]);

        if ($user->hasRole('institution-admin')) {

            $institutionUser = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$institutionUser ||
                (int) $attendance->liveClass->institution_id !==
                (int) $institutionUser->institution_id ||
                (int) $attendance->studentProfile->institution_id !==
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
                (int) $attendance->liveClass->teacher_profile_id !==
                (int) $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized live class access.'
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
                (int) $attendance->student_profile_id !==
                (int) $studentProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized attendance access.'
                );
            }

            return;
        }

        if ($user->hasRole('parent')) {

            abort(
                403,
                'Parents cannot access live class attendance.'
            );
        }

        abort(
            403,
            'Unauthorized.'
        );
    }

    private function authorizeLiveClassAttendanceManagement(
        LiveClass $liveClass,
        StudentProfile $studentProfile
    ): void {

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        if (
            (int) $liveClass->institution_id !==
            (int) $studentProfile->institution_id
        ) {

            abort(
                422,
                'Live class and student must belong to the same institution.'
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
                (int) $liveClass->institution_id
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

        abort(
            403,
            'Only staff may manage attendance.'
        );
    }
}
