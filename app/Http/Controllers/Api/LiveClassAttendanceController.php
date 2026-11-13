<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AssessmentLockSetChanged;
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
use App\Services\CourseAssessmentLockService;
use App\Http\Requests\StoreLiveClassAttendanceRequest;
use App\Http\Requests\UpdateLiveClassAttendanceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LiveClassAttendanceController extends BaseApiController
{
    public function __construct(
        private CourseAssessmentLockService $courseAssessmentLockService
    ) {
    }

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

    public function store(StoreLiveClassAttendanceRequest $request): JsonResponse
    {
        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validated();

        $liveClassId = (int) $validated['live_class_id'];
        $studentProfileId = (int) $validated['student_profile_id'];

        $attendance = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use (
                $validated,
                $liveClassId,
                $studentProfileId
            ): LiveClassAttendance {
                $discoveredCourseId = $this->discoverActiveLiveClassCourseId(
                    $liveClassId
                );
                $course = $this->courseAssessmentLockService->lockCourse(
                    $discoveredCourseId,
                    withTrashed: false
                );
                $liveClass = $this->courseAssessmentLockService
                    ->lockLiveClass($liveClassId, withTrashed: false);
                $currentCourseId = (int) $liveClass->course_id;

                if ($currentCourseId !== $discoveredCourseId) {
                    throw new AssessmentLockSetChanged([
                        $discoveredCourseId,
                        $currentCourseId,
                    ]);
                }

                if ((int) $course->id !== $currentCourseId) {
                    throw new AssessmentLockSetChanged([
                        (int) $course->id,
                        $currentCourseId,
                    ]);
                }

                $studentProfile = $this->courseAssessmentLockService
                    ->lockStudent($studentProfileId);
                $this->authorizeLiveClassAttendanceManagement(
                    $liveClass,
                    $studentProfile
                );

                if ($liveClass->status === 'cancelled') {
                    abort(
                        422,
                        'Attendance cannot be recorded for a cancelled live class.'
                    );
                }

                if (
                    $this->courseAssessmentLockService
                        ->lockLiveClassAttendancePair($liveClassId, $studentProfileId)
                ) {
                    throw ValidationException::withMessages([
                        'student_profile_id' => 'The student profile id has already been taken.',
                    ]);
                }

                return LiveClassAttendance::create($validated)->load([
                    'liveClass',
                    'studentProfile.user',
                    'studentProfile.institution',
                    'studentProfile.batch',
                ]);
            });

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
        UpdateLiveClassAttendanceRequest $request,
        LiveClassAttendance $liveClassAttendance
    ): JsonResponse {
        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validated();

        $attendanceId = (int) $liveClassAttendance->getKey();

        $updatedAttendance = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use (
                $attendanceId,
                $validated
            ): LiveClassAttendance {
                $identity = $this->discoverActiveAttendanceIdentity($attendanceId);
                $liveClassId = (int) $identity->live_class_id;
                $studentProfileId = (int) $identity->student_profile_id;
                $courseId = $this->discoverActiveLiveClassCourseId($liveClassId);
                $course = $this->courseAssessmentLockService->lockCourse(
                    $courseId,
                    withTrashed: false
                );
                $liveClass = $this->courseAssessmentLockService
                    ->lockLiveClass($liveClassId, withTrashed: false);

                if ((int) $liveClass->course_id !== $courseId) {
                    throw new AssessmentLockSetChanged([
                        $courseId,
                        (int) $liveClass->course_id,
                    ]);
                }

                if ((int) $course->id !== (int) $liveClass->course_id) {
                    throw new AssessmentLockSetChanged([
                        (int) $course->id,
                        (int) $liveClass->course_id,
                    ]);
                }

                $studentProfile = $this->courseAssessmentLockService
                    ->lockStudent($studentProfileId);
                $attendance = $this->courseAssessmentLockService
                    ->lockLiveClassAttendance($attendanceId, withTrashed: false);

                if (
                    (int) $attendance->live_class_id !== $liveClassId ||
                    (int) $attendance->student_profile_id !== $studentProfileId
                ) {
                    $currentCourseId = $this->discoverActiveLiveClassCourseId(
                        (int) $attendance->live_class_id
                    );
                    throw new AssessmentLockSetChanged(
                        [$courseId, $currentCourseId],
                        [$studentProfileId, (int) $attendance->student_profile_id]
                    );
                }

                $attendance->setRelation('liveClass', $liveClass);
                $attendance->setRelation('studentProfile', $studentProfile);
                $this->authorizeLiveClassAttendanceAccess($attendance);

                if ($liveClass->status === 'cancelled') {
                    abort(
                        422,
                        'Attendance cannot be modified for a cancelled live class.'
                    );
                }

                $attendance->fill($validated);
                $attendance->save();

                return $attendance->fresh()->load([
                    'liveClass',
                    'studentProfile.user',
                    'studentProfile.institution',
                    'studentProfile.batch',
                ]);
            });

        return $this->successResponse(
            $updatedAttendance,
            'Live class attendance updated successfully.'
        );
    }

    public function destroy(
        LiveClassAttendance $liveClassAttendance
    ): JsonResponse {
        $attendanceId = (int) $liveClassAttendance->getKey();

        $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($attendanceId): void {
                $identity = $this->discoverActiveAttendanceIdentity($attendanceId);
                $liveClassId = (int) $identity->live_class_id;
                $studentProfileId = (int) $identity->student_profile_id;
                $courseId = $this->discoverActiveLiveClassCourseId($liveClassId);
                $course = $this->courseAssessmentLockService->lockCourse(
                    $courseId,
                    withTrashed: false
                );
                $liveClass = $this->courseAssessmentLockService
                    ->lockLiveClass($liveClassId, withTrashed: false);

                if ((int) $liveClass->course_id !== $courseId) {
                    throw new AssessmentLockSetChanged([
                        $courseId,
                        (int) $liveClass->course_id,
                    ]);
                }

                if ((int) $course->id !== (int) $liveClass->course_id) {
                    throw new AssessmentLockSetChanged([
                        (int) $course->id,
                        (int) $liveClass->course_id,
                    ]);
                }

                $studentProfile = $this->courseAssessmentLockService
                    ->lockStudent($studentProfileId);
                $attendance = $this->courseAssessmentLockService
                    ->lockLiveClassAttendance($attendanceId, withTrashed: false);

                if (
                    (int) $attendance->live_class_id !== $liveClassId ||
                    (int) $attendance->student_profile_id !== $studentProfileId
                ) {
                    $currentCourseId = $this->discoverActiveLiveClassCourseId(
                        (int) $attendance->live_class_id
                    );
                    throw new AssessmentLockSetChanged(
                        [$courseId, $currentCourseId],
                        [$studentProfileId, (int) $attendance->student_profile_id]
                    );
                }

                $attendance->setRelation('liveClass', $liveClass);
                $attendance->setRelation('studentProfile', $studentProfile);
                $this->authorizeLiveClassAttendanceAccess($attendance);

                if ($liveClass->status === 'cancelled') {
                    abort(
                        422,
                        'Attendance cannot be deleted for a cancelled live class.'
                    );
                }

                $attendance->delete();
            });

        return $this->successResponse(
            null,
            'Live class attendance deleted successfully.'
        );
    }

    private function discoverActiveAttendanceIdentity(int $attendanceId): object
    {
        $identity = DB::table('live_class_attendances')
            ->where('id', $attendanceId)
            ->whereNull('deleted_at')
            ->first(['live_class_id', 'student_profile_id']);

        if (!$identity) {
            abort(404, 'Live class attendance not found.');
        }

        return $identity;
    }

    private function discoverActiveLiveClassCourseId(int $liveClassId): int
    {
        $courseId = DB::table('live_classes')
            ->where('id', $liveClassId)
            ->whereNull('deleted_at')
            ->value('course_id');

        if ($courseId === null) {
            abort(404, 'Live class not found.');
        }

        return (int) $courseId;
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
