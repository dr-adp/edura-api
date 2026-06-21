<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\InstitutionUser;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\ParentProfile;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LessonProgressController extends Controller
{
    private const PROGRESS_RELATIONS = [
        'courseEnrollment.course',
        'courseEnrollment.studentProfile.user',
        'lesson',
    ];

    private const MODIFIABLE_ENROLLMENT_STATUSES = [
        'active',
        'completed',
    ];

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = LessonProgress::with(self::PROGRESS_RELATIONS);

        /*
        |--------------------------------------------------------------------------
        | Scoped Progress Listing
        |--------------------------------------------------------------------------
        */
        $this->scopeProgressQuery($query, $user);

        return response()->json([
            'message' => 'Lesson progress records fetched successfully.',
            'data' => $query->latest()->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'course_enrollment_id' => ['required', 'exists:course_enrollments,id'],
            'lesson_id' => [
                'required',
                'exists:lessons,id',
                Rule::unique('lesson_progress', 'lesson_id')
                    ->where('course_enrollment_id', $request->course_enrollment_id),
            ],
            'status' => ['nullable', 'in:not_started,in_progress,completed'],
            'progress_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'watch_time_minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        $courseEnrollment = CourseEnrollment::with([
            'course',
            'studentProfile',
        ])->findOrFail($validated['course_enrollment_id']);
        $lesson = Lesson::findOrFail($validated['lesson_id']);

        /*
        |--------------------------------------------------------------------------
        | Ownership And Course Integrity Check
        |--------------------------------------------------------------------------
        */
        $this->authorizeEnrollmentAccess($courseEnrollment, $user, true);
        $this->ensureLessonBelongsToEnrollment($lesson, $courseEnrollment);

        $validated['status'] = $validated['status'] ?? 'in_progress';
        $validated['progress_percentage'] = $validated['progress_percentage'] ?? 0;

        if ($validated['status'] === 'in_progress') {
            $validated['started_at'] = now();
        }

        if ($validated['status'] === 'completed') {
            $validated['progress_percentage'] = 100;
            $validated['completed_at'] = now();
        }

        $lessonProgress = DB::transaction(function () use ($validated, $courseEnrollment) {
            $lessonProgress = LessonProgress::create($validated);

            $this->recalculateCourseEnrollmentProgress($courseEnrollment);

            return $lessonProgress;
        });

        return response()->json([
            'message' => 'Lesson progress created successfully.',
            'data' => $lessonProgress->load(self::PROGRESS_RELATIONS),
        ], 201);
    }

    public function show(LessonProgress $lessonProgress): JsonResponse
    {
        $this->authorizeLessonProgressAccess($lessonProgress);

        return response()->json([
            'message' => 'Lesson progress fetched successfully.',
            'data' => $lessonProgress->load(self::PROGRESS_RELATIONS),
        ]);
    }

    public function update(Request $request, LessonProgress $lessonProgress): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $this->authorizeLessonProgressMutation($lessonProgress, $user);

        $validated = $request->validate([
            'course_enrollment_id' => ['sometimes', 'exists:course_enrollments,id'],
            'lesson_id' => [
                'sometimes',
                'exists:lessons,id',
                Rule::unique('lesson_progress', 'lesson_id')
                    ->where(
                        'course_enrollment_id',
                        $request->course_enrollment_id
                            ?? $lessonProgress->course_enrollment_id
                    )
                    ->ignore($lessonProgress->id),
            ],
            'status' => ['nullable', 'in:not_started,in_progress,completed'],
            'progress_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'watch_time_minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        $originalEnrollment = $lessonProgress->courseEnrollment;
        $targetEnrollmentId = $validated['course_enrollment_id']
            ?? $lessonProgress->course_enrollment_id;
        $targetEnrollment = CourseEnrollment::with([
            'course',
            'studentProfile',
        ])->findOrFail($targetEnrollmentId);
        $targetLesson = Lesson::findOrFail(
            $validated['lesson_id'] ?? $lessonProgress->lesson_id
        );

        /*
        |--------------------------------------------------------------------------
        | Target Ownership And Course Integrity Check
        |--------------------------------------------------------------------------
        */
        $this->authorizeEnrollmentAccess($targetEnrollment, $user, true);
        $this->ensureLessonBelongsToEnrollment($targetLesson, $targetEnrollment);

        if (
            ($validated['status'] ?? null) === 'in_progress' &&
            ! $lessonProgress->started_at
        ) {
            $validated['started_at'] = now();
        }

        if (($validated['status'] ?? null) === 'completed') {
            $validated['progress_percentage'] = 100;
            $validated['completed_at'] = now();
        } elseif (array_key_exists('status', $validated)) {
            $validated['completed_at'] = null;
        }

        DB::transaction(function () use (
            $lessonProgress,
            $validated,
            $originalEnrollment,
            $targetEnrollment
        ) {
            $lessonProgress->update($validated);

            $this->recalculateCourseEnrollmentProgress($originalEnrollment);

            if ((int) $originalEnrollment->id !== (int) $targetEnrollment->id) {
                $this->recalculateCourseEnrollmentProgress($targetEnrollment);
            }
        });

        return response()->json([
            'message' => 'Lesson progress updated successfully.',
            'data' => $lessonProgress->fresh()->load(self::PROGRESS_RELATIONS),
        ]);
    }

    public function destroy(LessonProgress $lessonProgress): JsonResponse
    {
        $this->authorizeLessonProgressMutation($lessonProgress);

        $courseEnrollment = $lessonProgress->courseEnrollment;

        DB::transaction(function () use ($lessonProgress, $courseEnrollment) {
            $lessonProgress->delete();

            $this->recalculateCourseEnrollmentProgress($courseEnrollment);
        });

        return response()->json([
            'message' => 'Lesson progress deleted successfully.',
        ]);
    }

    private function recalculateCourseEnrollmentProgress(
        CourseEnrollment $courseEnrollment
    ): void {
        $courseEnrollment->loadMissing('course');

        $course = $courseEnrollment->course;

        $totalLessons = Lesson::where('course_id', $course->id)
            ->where('status', 'published')
            ->count();

        if ($totalLessons === 0) {
            $courseEnrollment->update([
                'progress_percentage' => 0,
                'status' => 'active',
                'completed_at' => null,
            ]);

            return;
        }

        $completedLessons = LessonProgress::where(
            'course_enrollment_id',
            $courseEnrollment->id
        )
            ->where('status', 'completed')
            ->whereHas('lesson', function (Builder $lessonQuery) use ($course) {
                $lessonQuery
                    ->where('course_id', $course->id)
                    ->where('status', 'published');
            })
            ->distinct('lesson_id')
            ->count('lesson_id');

        $percentage = min(
            100,
            round(($completedLessons / $totalLessons) * 100, 2)
        );

        $courseEnrollment->update([
            'progress_percentage' => $percentage,
            'status' => $percentage >= 100 ? 'completed' : 'active',
            'completed_at' => $percentage >= 100 ? now() : null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scoping
    |--------------------------------------------------------------------------
    */
    private function scopeProgressQuery(Builder $query, User $user): void
    {
        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            $query
                ->whereHas(
                    'courseEnrollment.course',
                    function (Builder $courseQuery) use ($institutionUser) {
                        $courseQuery->where(
                            'institution_id',
                            $institutionUser->institution_id
                        );
                    }
                )
                ->whereHas(
                    'courseEnrollment.studentProfile',
                    function (Builder $studentQuery) use ($institutionUser) {
                        $studentQuery->where(
                            'institution_id',
                            $institutionUser->institution_id
                        );
                    }
                );

            return;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = $this->teacherProfileFor($user);

            $query->whereHas(
                'courseEnrollment',
                function (Builder $enrollmentQuery) use ($teacherProfile) {
                    $enrollmentQuery
                        ->whereIn('status', self::MODIFIABLE_ENROLLMENT_STATUSES)
                        ->whereHas(
                            'course',
                            function (Builder $courseQuery) use ($teacherProfile) {
                                $courseQuery->where(
                                    'teacher_profile_id',
                                    $teacherProfile->id
                                );
                            }
                        );
                }
            );

            return;
        }

        if ($user->hasRole('student')) {
            $studentProfile = $this->studentProfileFor($user);

            $query->whereHas(
                'courseEnrollment',
                function (Builder $enrollmentQuery) use ($studentProfile) {
                    $enrollmentQuery->where(
                        'student_profile_id',
                        $studentProfile->id
                    );
                }
            );

            return;
        }

        if ($user->hasRole('parent')) {
            $parentProfile = $this->parentProfileFor($user);

            $query->whereHas(
                'courseEnrollment',
                function (Builder $enrollmentQuery) use ($parentProfile) {
                    $enrollmentQuery->where(
                        'student_profile_id',
                        $parentProfile->student_profile_id
                    );
                }
            );

            return;
        }

        abort(403, 'Unauthorized role.');
    }

    /*
    |--------------------------------------------------------------------------
    | Record Authorization
    |--------------------------------------------------------------------------
    */
    private function authorizeLessonProgressAccess(
        LessonProgress $lessonProgress,
        ?User $user = null
    ): void {
        $user ??= Auth::user();

        $lessonProgress->loadMissing([
            'courseEnrollment.course',
            'courseEnrollment.studentProfile',
        ]);

        $this->authorizeEnrollmentAccess(
            $lessonProgress->courseEnrollment,
            $user,
            false
        );
    }

    private function authorizeLessonProgressMutation(
        LessonProgress $lessonProgress,
        ?User $user = null
    ): void {
        $user ??= Auth::user();

        $lessonProgress->loadMissing([
            'courseEnrollment.course',
            'courseEnrollment.studentProfile',
        ]);

        $this->authorizeEnrollmentAccess(
            $lessonProgress->courseEnrollment,
            $user,
            true
        );
    }

    private function authorizeEnrollmentAccess(
        CourseEnrollment $courseEnrollment,
        User $user,
        bool $isMutation
    ): void {
        $courseEnrollment->loadMissing([
            'course',
            'studentProfile',
        ]);

        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            if (
                $courseEnrollment->course &&
                $courseEnrollment->studentProfile &&
                (int) $courseEnrollment->course->institution_id ===
                    (int) $institutionUser->institution_id &&
                (int) $courseEnrollment->studentProfile->institution_id ===
                    (int) $institutionUser->institution_id
            ) {
                return;
            }

            abort(403, 'Unauthorized institution access.');
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = $this->teacherProfileFor($user);

            if (
                $courseEnrollment->course &&
                (int) $courseEnrollment->course->teacher_profile_id ===
                    (int) $teacherProfile->id &&
                in_array(
                    $courseEnrollment->status,
                    self::MODIFIABLE_ENROLLMENT_STATUSES,
                    true
                )
            ) {
                return;
            }

            abort(403, 'Unauthorized: Student is not enrolled in your course.');
        }

        if ($user->hasRole('student')) {
            $studentProfile = $this->studentProfileFor($user);

            if (
                (int) $courseEnrollment->student_profile_id ===
                (int) $studentProfile->id &&
                (
                    ! $isMutation ||
                    in_array(
                        $courseEnrollment->status,
                        self::MODIFIABLE_ENROLLMENT_STATUSES,
                        true
                    )
                )
            ) {
                return;
            }

            abort(403, 'Unauthorized: You can only modify your own active progress.');
        }

        if ($user->hasRole('parent')) {
            $parentProfile = $this->parentProfileFor($user);

            if ($isMutation) {
                abort(403, 'Unauthorized: Lesson progress is read-only for parents.');
            }

            if (
                (int) $courseEnrollment->student_profile_id ===
                (int) $parentProfile->student_profile_id
            ) {
                return;
            }

            abort(403, 'Unauthorized: You can only view your child progress.');
        }

        abort(403, 'Unauthorized role.');
    }

    private function ensureLessonBelongsToEnrollment(
        Lesson $lesson,
        CourseEnrollment $courseEnrollment
    ): void {
        if ((int) $lesson->course_id === (int) $courseEnrollment->course_id) {
            return;
        }

        throw ValidationException::withMessages([
            'lesson_id' => 'Lesson does not belong to the enrolled course.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Role Profiles
    |--------------------------------------------------------------------------
    */
    private function institutionUserFor(User $user): InstitutionUser
    {
        $institutionUser = InstitutionUser::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($institutionUser) {
            return $institutionUser;
        }

        abort(403, 'Unauthorized: Institution profile not found.');
    }

    private function teacherProfileFor(User $user): TeacherProfile
    {
        $teacherProfile = TeacherProfile::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($teacherProfile) {
            return $teacherProfile;
        }

        abort(403, 'Unauthorized: Teacher profile not found.');
    }

    private function studentProfileFor(User $user): StudentProfile
    {
        $studentProfile = StudentProfile::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($studentProfile) {
            return $studentProfile;
        }

        abort(403, 'Unauthorized: Student profile not found.');
    }

    private function parentProfileFor(User $user): ParentProfile
    {
        $parentProfile = ParentProfile::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($parentProfile && $parentProfile->student_profile_id) {
            return $parentProfile;
        }

        abort(403, 'Unauthorized: Parent profile not found.');
    }
}
