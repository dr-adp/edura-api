<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\InstitutionUser;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseEnrollmentService;
use App\Services\ParentStudentAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\StoreLessonProgressRequest;
use App\Http\Requests\UpdateLessonProgressRequest;

class LessonProgressController extends Controller
{
    public function __construct(
        private CourseEnrollmentService $courseEnrollmentService,
        private ParentStudentAccessService $parentStudentAccessService
    ) {
    }

    private const PROGRESS_RELATIONS = [
        'courseEnrollment.course',
        'courseEnrollment.studentProfile.user',
        'lesson.courseSection',
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
        $progresses = $query->latest()->paginate(20);

        $progresses->getCollection()->each(function (LessonProgress $progress) {
            $this->sanitizeLessonSectionForResponse($progress);
        });

        return response()->json([
            'message' => 'Lesson progress records fetched successfully.',
            'data' => $progresses,
        ]);
    }

    public function store(StoreLessonProgressRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validated();

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
        $this->rejectLearnerCompletionFields($user, $validated);

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

            $this->courseEnrollmentService->recalculateProgress($courseEnrollment);

            return $lessonProgress;
        });

        return response()->json([
            'message' => 'Lesson progress created successfully.',
            'data' => $this->sanitizeLessonSectionForResponse(
                $lessonProgress->load(self::PROGRESS_RELATIONS)
            ),
        ], 201);
    }

    public function show(LessonProgress $lessonProgress): JsonResponse
    {
        $this->authorizeLessonProgressAccess($lessonProgress);

        return response()->json([
            'message' => 'Lesson progress fetched successfully.',
            'data' => $this->sanitizeLessonSectionForResponse(
                $lessonProgress->load(self::PROGRESS_RELATIONS)
            ),
        ]);
    }

    public function update(UpdateLessonProgressRequest $request, LessonProgress $lessonProgress): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $this->authorizeLessonProgressMutation($lessonProgress, $user);

        $validated = $request->validated();

        if (
            $user->hasRole('student') &&
            isset($validated['lesson_id']) &&
            (int) $validated['lesson_id'] !== (int) $lessonProgress->lesson_id
        ) {
            throw ValidationException::withMessages([
                'lesson_id' => 'Students cannot change the lesson for an existing progress record.',
            ]);
        }

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
        $this->rejectLearnerCompletionFields($user, $validated);
        $validated = $this->normalizeProgressUpdate($lessonProgress, $validated);

        DB::transaction(function () use (
            $lessonProgress,
            $validated,
            $originalEnrollment,
            $targetEnrollment
        ) {
            $lessonProgress->update($validated);

            $this->courseEnrollmentService->recalculateProgress($originalEnrollment);

            if ((int) $originalEnrollment->id !== (int) $targetEnrollment->id) {
                $this->courseEnrollmentService->recalculateProgress($targetEnrollment);
            }
        });

        return response()->json([
            'message' => 'Lesson progress updated successfully.',
            'data' => $this->sanitizeLessonSectionForResponse(
                $lessonProgress->fresh()->load(self::PROGRESS_RELATIONS)
            ),
        ]);
    }

    public function complete(LessonProgress $lessonProgress): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $this->authorizeLessonProgressMutation($lessonProgress, $user);

        $courseEnrollment = $lessonProgress->courseEnrollment;
        $lesson = $lessonProgress->lesson;

        $this->ensureLessonBelongsToEnrollment($lesson, $courseEnrollment);

        if ($lesson->status !== 'published') {
            throw ValidationException::withMessages([
                'lesson_id' => 'Only published lessons can be completed.',
            ]);
        }

        if ($user->hasRole('student')) {
            $this->ensureRequiredLessonAssessmentsComplete(
                $lesson,
                $courseEnrollment
            );
        }

        DB::transaction(function () use ($lessonProgress, $courseEnrollment) {
            $lessonProgress->update([
                'status' => 'completed',
                'progress_percentage' => 100,
                'started_at' => $lessonProgress->started_at ?? now(),
                'completed_at' => $lessonProgress->completed_at ?? now(),
            ]);

            $this->courseEnrollmentService->recalculateProgress($courseEnrollment);
        });

        return response()->json([
            'message' => 'Lesson completed successfully.',
            'data' => $this->sanitizeLessonSectionForResponse(
                $lessonProgress->fresh()->load(self::PROGRESS_RELATIONS)
            ),
        ]);
    }

    public function destroy(LessonProgress $lessonProgress): JsonResponse
    {
        $this->authorizeLessonProgressMutation($lessonProgress);

        $courseEnrollment = $lessonProgress->courseEnrollment;

        DB::transaction(function () use ($lessonProgress, $courseEnrollment) {
            $lessonProgress->delete();

            $this->courseEnrollmentService->recalculateProgress($courseEnrollment);
        });

        return response()->json([
            'message' => 'Lesson progress deleted successfully.',
        ]);
    }

    /**
     * Learners can report partial progress, but completion is recorded only
     * through the dedicated completion action.
     */
    private function rejectLearnerCompletionFields(
        User $user,
        array $validated
    ): void {
        if (! $user->hasRole('student')) {
            return;
        }

        $errors = [];

        if (($validated['status'] ?? null) === 'completed') {
            $errors['status'] = 'Use the lesson completion action to complete a lesson.';
        }

        if (
            array_key_exists('progress_percentage', $validated) &&
            $validated['progress_percentage'] !== null &&
            (float) $validated['progress_percentage'] >= 100
        ) {
            $errors['progress_percentage'] = 'Lesson completion must use the lesson completion action.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Hide a stale section reference in the serialized lesson without
     * changing the persisted lesson record.
     */
    private function sanitizeLessonSectionForResponse(
        LessonProgress $lessonProgress
    ): LessonProgress {
        $lesson = $lessonProgress->lesson;

        if (! $lesson) {
            return $lessonProgress;
        }

        if ($lesson->course_section_id === null) {
            $lesson->unsetRelation('courseSection');

            return $lessonProgress;
        }

        $section = $lesson->courseSection;

        if (
            ! $section ||
            (int) $section->course_id !== (int) $lesson->course_id
        ) {
            $lesson->setAttribute('course_section_id', null);
            $lesson->setRelation('courseSection', null);
        } else {
            // Keep the existing progress response shape for valid relationships.
            $lesson->unsetRelation('courseSection');
        }

        return $lessonProgress;
    }

    private function normalizeProgressUpdate(
        LessonProgress $lessonProgress,
        array $validated
    ): array {
        $hasStatus = isset($validated['status']);
        $status = $validated['status'] ?? $lessonProgress->status;
        $hasProgressPercentage = array_key_exists('progress_percentage', $validated)
            && $validated['progress_percentage'] !== null;
        $progressPercentage = $hasProgressPercentage
            ? (float) $validated['progress_percentage']
            : (float) $lessonProgress->progress_percentage;

        if ($status === 'completed') {
            $validated['status'] = 'completed';
            $validated['progress_percentage'] = 100;
            $validated['completed_at'] = $lessonProgress->completed_at ?? now();
            $validated['started_at'] = $lessonProgress->started_at ?? now();

            return $validated;
        }

        if ($status === 'not_started') {
            if (! $hasStatus && $hasProgressPercentage && $progressPercentage > 0) {
                $status = 'in_progress';
                $validated['status'] = 'in_progress';
            } else {
                $validated['progress_percentage'] = 0;
                $validated['completed_at'] = null;

                return $validated;
            }
        }

        if (
            $status === 'in_progress' &&
            $hasProgressPercentage &&
            $progressPercentage >= 100
        ) {
            throw ValidationException::withMessages([
                'progress_percentage' => 'Use completed status to record 100% progress.',
            ]);
        }

        if ($status === 'in_progress' && $progressPercentage >= 100) {
            $validated['progress_percentage'] = 0;
        }

        $validated['completed_at'] = null;

        if ($status === 'in_progress' && ! $lessonProgress->started_at) {
            $validated['started_at'] = now();
        }

        return $validated;
    }

    /**
     * Published assessments explicitly attached to a lesson are considered
     * completion requirements. Course-level assessments are not included.
     */
    private function ensureRequiredLessonAssessmentsComplete(
        Lesson $lesson,
        CourseEnrollment $courseEnrollment
    ): void {
        $quizIds = Quiz::where('lesson_id', $lesson->id)
            ->where('course_id', $courseEnrollment->course_id)
            ->where('status', 'published')
            ->pluck('id');

        $assignmentIds = Assignment::where('lesson_id', $lesson->id)
            ->where('course_id', $courseEnrollment->course_id)
            ->where('status', 'published')
            ->pluck('id');

        if ($quizIds->isEmpty() && $assignmentIds->isEmpty()) {
            throw ValidationException::withMessages([
                'assessment' => 'This lesson has no verified completion pathway configured.',
            ]);
        }

        foreach ($quizIds as $quizId) {
            $hasPassedAttempt = QuizAttempt::where('quiz_id', $quizId)
                ->where('student_profile_id', $courseEnrollment->student_profile_id)
                ->where('status', 'evaluated')
                ->where('result_status', 'passed')
                ->whereNotNull('submitted_at')
                ->whereDoesntHave('answers', function (Builder $answerQuery) {
                    $answerQuery
                        ->whereNull('manually_graded_at')
                        ->whereHas('questionBank', function (Builder $questionQuery) {
                            $questionQuery->whereIn(
                                'question_type',
                                QuestionBank::MANUAL_QUESTION_TYPES
                            );
                        });
                })
                ->exists();

            if (! $hasPassedAttempt) {
                throw ValidationException::withMessages([
                    'assessment' => 'Pass each published quiz attached to this lesson before completing it.',
                ]);
            }
        }

        foreach ($assignmentIds as $assignmentId) {
            $hasSubmittedAssignment = AssignmentSubmission::where(
                'assignment_id',
                $assignmentId
            )
                ->where('student_profile_id', $courseEnrollment->student_profile_id)
                ->whereIn('status', ['submitted', 'reviewed', 'returned'])
                ->whereNotNull('submitted_at')
                ->where(function (Builder $submissionQuery) {
                    $submissionQuery
                        ->where(function (Builder $payloadQuery) {
                            $payloadQuery
                                ->whereNotNull('submission_text')
                                ->whereRaw("TRIM(submission_text) <> ''");
                        })
                        ->orWhere(function (Builder $payloadQuery) {
                            $payloadQuery
                                ->whereNotNull('external_url')
                                ->whereRaw("TRIM(external_url) <> ''");
                        })
                        ->orWhere(function (Builder $payloadQuery) {
                            $payloadQuery
                                ->whereNotNull('file_path')
                                ->whereRaw("TRIM(file_path) <> ''");
                        });
                })
                ->exists();

            if (! $hasSubmittedAssignment) {
                throw ValidationException::withMessages([
                    'assessment' => 'Submit each published assignment attached to this lesson before completing it.',
                ]);
            }
        }
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

        $query->whereHas(
            'courseEnrollment',
            fn (Builder $enrollmentQuery) => $enrollmentQuery
                ->institutionallyConsistent()
        );

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
            $query->whereHas(
                'courseEnrollment',
                function (Builder $enrollmentQuery) use ($user) {
                    $this->parentStudentAccessService->scopeStudentRecords(
                        $enrollmentQuery,
                        $user,
                        'course_enrollments.student_profile_id'
                    );
                }
            )->whereExists(function ($relatedLessonQuery) {
                $relatedLessonQuery->selectRaw('1')
                    ->from('course_enrollments as linked_enrollments')
                    ->join(
                        'lessons as linked_lessons',
                        'linked_lessons.id',
                        '=',
                        'lesson_progress.lesson_id'
                    )
                    ->whereColumn(
                        'linked_enrollments.id',
                        'lesson_progress.course_enrollment_id'
                    )
                    ->whereColumn(
                        'linked_lessons.course_id',
                        'linked_enrollments.course_id'
                    );
            });

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

        if ($user->hasRole('parent')) {
            $lessonProgress->loadMissing('lesson');

            if (
                ! $lessonProgress->lesson ||
                (int) $lessonProgress->lesson->course_id !==
                    (int) $lessonProgress->courseEnrollment->course_id
            ) {
                abort(403, 'Lesson progress does not belong to the linked child course.');
            }
        }
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

        if (!$courseEnrollment->hasConsistentInstitutions()) {
            abort(403, 'Course and student must belong to the same institution.');
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
            if ($isMutation) {
                abort(403, 'Unauthorized: Lesson progress is read-only for parents.');
            }

            $this->parentStudentAccessService->authorizeStudent(
                $user,
                (int) $courseEnrollment->student_profile_id,
                'Unauthorized: You can only view your child progress.'
            );

            return;
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

}
