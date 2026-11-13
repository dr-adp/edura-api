<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Exceptions\AssessmentLockSetChanged;
use App\Models\Assignment;
use App\Models\AssignmentEvaluation;
use App\Models\AssignmentSubmission;
use App\Models\CourseEnrollment;
use App\Models\InstitutionUser;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CourseAssessmentLockService;
use App\Services\ParentStudentAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\StoreAssignmentEvaluationRequest;
use App\Http\Requests\UpdateAssignmentEvaluationRequest;

class AssignmentEvaluationController extends BaseApiController
{
    public function __construct(
        private ParentStudentAccessService $parentStudentAccessService,
        private CourseAssessmentLockService $courseAssessmentLockService
    ) {
    }

    private const EVALUATION_RELATIONS = [
        'assignmentSubmission.assignment.course',
        'assignmentSubmission.studentProfile.user',
        'teacherProfile.user',
    ];

    private const VALID_ENROLLMENT_STATUSES = [
        'active',
        'completed',
    ];

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = AssignmentEvaluation::with(self::EVALUATION_RELATIONS);

        /*
        |--------------------------------------------------------------------------
        | Scoped Evaluation Listing
        |--------------------------------------------------------------------------
        */
        $this->scopeEvaluationQuery($query, $user);

        $evaluations = $query
            ->latest()
            ->paginate(20);

        return $this->successResponse(
            $evaluations,
            'Assignment evaluations fetched successfully.'
        );
    }

    public function store(StoreAssignmentEvaluationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validated();

        $evaluation = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use ($validated, $user): AssignmentEvaluation {
                $context = $this->lockEvaluationMutationContext(
                    [(int) $validated['assignment_submission_id']]
                );
                /** @var AssignmentSubmission $submission */
                $submission = $context['submissions']->first();

                $this->authorizeSubmissionAccess(
                    $submission,
                    $user,
                    true,
                    $context['enrollments']
                );

                if ($context['evaluations']->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'assignment_submission_id' => 'The assignment submission has already been evaluated.',
                    ]);
                }

                $maximumMarks = $this->resolveMaximumMarks(
                    $validated['maximum_marks'] ?? null,
                    $submission,
                    $user
                );
                $marksObtained = (float) $validated['marks_obtained'];
                $this->ensureMarksWithinMaximum($marksObtained, $maximumMarks);

                $attributes = $validated;
                $attributes['teacher_profile_id'] = $this->resolveEvaluatorProfileId(
                    $validated['teacher_profile_id'] ?? null,
                    $submission,
                    $user
                );
                $attributes['maximum_marks'] = $maximumMarks;
                $attributes['result_status'] = $this->calculateResultStatus(
                    $marksObtained,
                    $maximumMarks
                );
                $attributes['evaluated_at'] = now();

                $evaluation = AssignmentEvaluation::create($attributes);
                $submission->update(['status' => 'reviewed']);

                return $evaluation;
            });

        return $this->successResponse(
            $evaluation->load(self::EVALUATION_RELATIONS),
            'Assignment evaluated successfully.',
            201
        );
    }

    public function show(AssignmentEvaluation $assignmentEvaluation): JsonResponse
    {
        $this->authorizeEvaluationAccess($assignmentEvaluation);

        return $this->successResponse(
            $assignmentEvaluation->load(self::EVALUATION_RELATIONS),
            'Assignment evaluation fetched successfully.'
        );
    }

    public function update(
        UpdateAssignmentEvaluationRequest $request,
        AssignmentEvaluation $assignmentEvaluation
    ): JsonResponse {
        /** @var User $user */
        $user = Auth::user();

        $this->authorizeEvaluationMutation($assignmentEvaluation, $user);

        $validated = $request->validated();

        $updatedEvaluation = $this->courseAssessmentLockService
            ->transactionWithLockSetRetries(function () use (
                $assignmentEvaluation,
                $validated,
                $user
            ): AssignmentEvaluation {
                $context = $this->lockEvaluationMutationContext(
                    isset($validated['assignment_submission_id'])
                        ? [(int) $validated['assignment_submission_id']]
                        : [],
                    (int) $assignmentEvaluation->id
                );
                /** @var AssignmentEvaluation $lockedEvaluation */
                $lockedEvaluation = $context['evaluation'];
                /** @var AssignmentSubmission $originalSubmission */
                $originalSubmission = $context['submissions']->first(
                    fn (AssignmentSubmission $submission): bool =>
                        (int) $submission->id === (int) $context['original_submission_id']
                );
                $targetSubmissionId = (int) (
                    $validated['assignment_submission_id']
                    ?? $lockedEvaluation->assignment_submission_id
                );
                /** @var AssignmentSubmission $targetSubmission */
                $targetSubmission = $context['submissions']->first(
                    fn (AssignmentSubmission $submission): bool =>
                        (int) $submission->id === $targetSubmissionId
                );

                $this->authorizeSubmissionAccess(
                    $originalSubmission,
                    $user,
                    true,
                    $context['enrollments']
                );
                $this->authorizeSubmissionAccess(
                    $targetSubmission,
                    $user,
                    true,
                    $context['enrollments']
                );

                $requestedMaximumMarks = array_key_exists('maximum_marks', $validated)
                    ? $validated['maximum_marks']
                    : ($user->hasRole('super-admin')
                        ? $lockedEvaluation->maximum_marks
                        : null);
                $maximumMarks = $this->resolveMaximumMarks(
                    $requestedMaximumMarks,
                    $targetSubmission,
                    $user
                );
                $marksObtained = (float) (
                    $validated['marks_obtained'] ?? $lockedEvaluation->marks_obtained
                );
                $this->ensureMarksWithinMaximum($marksObtained, $maximumMarks);

                $attributes = $validated;
                $attributes['teacher_profile_id'] = $this->resolveEvaluatorProfileId(
                    $validated['teacher_profile_id'] ?? $lockedEvaluation->teacher_profile_id,
                    $targetSubmission,
                    $user
                );
                $attributes['maximum_marks'] = $maximumMarks;
                $attributes['result_status'] = $this->calculateResultStatus(
                    $marksObtained,
                    $maximumMarks
                );
                $attributes['evaluated_at'] = now();

                $lockedEvaluation->fill($attributes);
                $lockedEvaluation->save();
                $targetSubmission->update(['status' => 'reviewed']);

                if ((int) $originalSubmission->id !== (int) $targetSubmission->id) {
                    $originalSubmission->update(['status' => 'submitted']);
                }

                return $lockedEvaluation;
            });

        return $this->successResponse(
            $updatedEvaluation->fresh()->load(self::EVALUATION_RELATIONS),
            'Assignment evaluation updated successfully.'
        );
    }

    public function destroy(AssignmentEvaluation $assignmentEvaluation): JsonResponse
    {
        $this->authorizeEvaluationMutation($assignmentEvaluation);

        $this->courseAssessmentLockService->transactionWithLockSetRetries(
            function () use ($assignmentEvaluation): void {
                $context = $this->lockEvaluationMutationContext(
                    [],
                    (int) $assignmentEvaluation->id
                );
                /** @var AssignmentSubmission $submission */
                $submission = $context['submissions']->first();
                /** @var AssignmentEvaluation $lockedEvaluation */
                $lockedEvaluation = $context['evaluation'];

                $this->authorizeSubmissionAccess(
                    $submission,
                    Auth::user(),
                    true,
                    $context['enrollments']
                );

                $lockedEvaluation->delete();
                $submission->update(['status' => 'submitted']);
            }
        );

        return $this->successResponse(
            null,
            'Assignment evaluation deleted successfully.'
        );
    }

    /**
     * Lock all affected parents before assignments, submissions, and
     * evaluations. Relationship drift raises a retry signal before another
     * parent lock is acquired out of order.
     *
     * @param  list<int>  $targetSubmissionIds
     * @return array{
     *     submissions:Collection<int, AssignmentSubmission>,
     *     enrollments:Collection<int, CourseEnrollment>,
     *     evaluations:Collection<int, AssignmentEvaluation>,
     *     evaluation:?AssignmentEvaluation,
     *     original_submission_id:?int
     * }
     */
    private function lockEvaluationMutationContext(
        array $targetSubmissionIds,
        ?int $evaluationId = null
    ): array {
        $evaluationIdentity = $evaluationId === null
            ? null
            : AssignmentEvaluation::query()->whereKey($evaluationId)->firstOrFail();
        $originalSubmissionId = $evaluationIdentity
            ? (int) $evaluationIdentity->assignment_submission_id
            : null;
        $submissionIds = array_values(array_unique(array_filter(array_merge(
            $targetSubmissionIds,
            $originalSubmissionId === null ? [] : [$originalSubmissionId]
        ))));
        sort($submissionIds, SORT_NUMERIC);

        $submissionIdentities = AssignmentSubmission::query()
            ->with([
                'assignment:id,course_id,teacher_profile_id,maximum_marks',
                'studentProfile:id,institution_id',
            ])
            ->whereIn('id', $submissionIds)
            ->orderBy('id')
            ->get([
                'id',
                'assignment_id',
                'student_profile_id',
                'deleted_at',
            ]);

        if ($submissionIdentities->count() !== count($submissionIds)) {
            abort(404);
        }

        foreach ($submissionIdentities as $submission) {
            if (! $submission->assignment || ! $submission->studentProfile) {
                abort(403, 'Unauthorized assignment submission.');
            }
        }

        $assignmentIdentities = $submissionIdentities
            ->map(fn (AssignmentSubmission $submission): Assignment => $submission->assignment)
            ->unique('id')
            ->values();
        $assignmentIds = $assignmentIdentities
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $courseIds = $assignmentIdentities
            ->pluck('course_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $studentProfileIds = $submissionIdentities
            ->pluck('student_profile_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        sort($courseIds, SORT_NUMERIC);
        sort($studentProfileIds, SORT_NUMERIC);

        $lockedCourses = collect();
        foreach ($courseIds as $courseId) {
            $lockedCourses->put(
                $courseId,
                $this->courseAssessmentLockService->lockCourse(
                    $courseId,
                    withTrashed: false
                )
            );
        }

        $lockedStudents = collect();
        foreach ($studentProfileIds as $studentProfileId) {
            $lockedStudents->put(
                $studentProfileId,
                $this->courseAssessmentLockService->lockStudent($studentProfileId)
            );
        }

        $pairs = $submissionIdentities
            ->map(static fn (AssignmentSubmission $submission): array => [
                'course_id' => (int) $submission->assignment->course_id,
                'student_profile_id' => (int) $submission->student_profile_id,
            ])
            ->all();
        $enrollments = $this->courseAssessmentLockService
            ->lockCourseEnrollments($pairs);

        $assignments = $this->courseAssessmentLockService->lockAssignments($assignmentIds);
        foreach ($assignmentIdentities as $assignmentIdentity) {
            $assignment = $assignments->firstWhere('id', $assignmentIdentity->id);

            if (
                ! $assignment ||
                $assignment->trashed() ||
                (int) $assignment->course_id !== (int) $assignmentIdentity->course_id
            ) {
                throw new AssessmentLockSetChanged(
                    array_merge($courseIds, [(int) ($assignment?->course_id ?? 0)]),
                    $studentProfileIds
                );
            }
        }

        $submissions = $this->courseAssessmentLockService
            ->lockAssignmentSubmissions($submissionIds);
        foreach ($submissionIdentities as $submissionIdentity) {
            $submission = $submissions->firstWhere('id', $submissionIdentity->id);

            if (
                ! $submission ||
                $submission->trashed() ||
                (int) $submission->assignment_id !== (int) $submissionIdentity->assignment_id ||
                (int) $submission->student_profile_id !== (int) $submissionIdentity->student_profile_id
            ) {
                $currentCourseId = (int) ($submission?->assignment?->course_id ?? 0);

                if (
                    $submission &&
                    ! in_array((int) $submission->assignment_id, $assignmentIds, true)
                ) {
                    $newAssignment = Assignment::withTrashed()
                        ->whereKey($submission->assignment_id)
                        ->first();
                    $currentCourseId = (int) ($newAssignment?->course_id ?? 0);
                }

                throw new AssessmentLockSetChanged(
                    array_merge($courseIds, [$currentCourseId]),
                    array_merge(
                        $studentProfileIds,
                        $submission ? [(int) $submission->student_profile_id] : []
                    )
                );
            }

            $assignment = $assignments->firstWhere('id', $submission->assignment_id);
            $assignment->setRelation(
                'course',
                $lockedCourses->get((int) $assignment->course_id)
            );
            $submission->setRelation('assignment', $assignment);
            $submission->setRelation(
                'studentProfile',
                $lockedStudents->get((int) $submission->student_profile_id)
            );
        }

        if ($evaluationId !== null) {
            $currentEvaluationIdentity = AssignmentEvaluation::query()
                ->whereKey($evaluationId)
                ->first();

            if (! $currentEvaluationIdentity) {
                abort(404);
            }

            if (! in_array(
                (int) $currentEvaluationIdentity->assignment_submission_id,
                $submissionIds,
                true
            )) {
                $newSubmission = AssignmentSubmission::query()
                    ->with('assignment:id,course_id')
                    ->whereKey($currentEvaluationIdentity->assignment_submission_id)
                    ->first();

                throw new AssessmentLockSetChanged(
                    array_merge($courseIds, [(int) ($newSubmission?->assignment?->course_id ?? 0)]),
                    array_merge($studentProfileIds, [(int) ($newSubmission?->student_profile_id ?? 0)])
                );
            }
        }

        $evaluations = $this->courseAssessmentLockService
            ->lockAssignmentEvaluationsForSubmissions($submissionIds, $evaluationId === null ? [] : [$evaluationId]);
        $lockedEvaluation = $evaluationId === null
            ? null
            : $evaluations->firstWhere('id', $evaluationId);

        if ($evaluationId !== null) {
            if (! $lockedEvaluation || $lockedEvaluation->trashed()) {
                throw new AssessmentLockSetChanged($courseIds, $studentProfileIds);
            }

            if (! in_array((int) $lockedEvaluation->assignment_submission_id, $submissionIds, true)) {
                $newSubmission = AssignmentSubmission::withTrashed()
                    ->with('assignment:id,course_id')
                    ->whereKey($lockedEvaluation->assignment_submission_id)
                    ->first();

                throw new AssessmentLockSetChanged(
                    array_merge($courseIds, [(int) ($newSubmission?->assignment?->course_id ?? 0)]),
                    array_merge($studentProfileIds, [(int) ($newSubmission?->student_profile_id ?? 0)])
                );
            }

            if ((int) $lockedEvaluation->assignment_submission_id !== $originalSubmissionId) {
                throw new AssessmentLockSetChanged($courseIds, $studentProfileIds);
            }
        }

        return [
            'submissions' => $submissions,
            'enrollments' => $enrollments,
            'evaluations' => $evaluations,
            'evaluation' => $lockedEvaluation,
            'original_submission_id' => $originalSubmissionId,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scoping
    |--------------------------------------------------------------------------
    */
    private function scopeEvaluationQuery(Builder $query, User $user): void
    {
        if ($user->hasRole('super-admin')) {
            return;
        }

        $query->whereHas(
            'assignmentSubmission',
            fn (Builder $submissionQuery) => $submissionQuery
                ->institutionallyConsistent()
        );

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            $query
                ->whereHas(
                    'assignmentSubmission.assignment.course',
                    function (Builder $courseQuery) use ($institutionUser) {
                        $courseQuery->where(
                            'institution_id',
                            $institutionUser->institution_id
                        );
                    }
                )
                ->whereHas(
                    'assignmentSubmission.studentProfile',
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

            $query->whereExists(function ($accessQuery) use ($teacherProfile) {
                $accessQuery->selectRaw('1')
                    ->from('assignment_submissions as scoped_submissions')
                    ->join(
                        'assignments as scoped_assignments',
                        'scoped_assignments.id',
                        '=',
                        'scoped_submissions.assignment_id'
                    )
                    ->join(
                        'courses as scoped_courses',
                        'scoped_courses.id',
                        '=',
                        'scoped_assignments.course_id'
                    )
                    ->join('course_enrollments as scoped_enrollments', function ($join) {
                        $join
                            ->on(
                                'scoped_enrollments.course_id',
                                '=',
                                'scoped_assignments.course_id'
                            )
                            ->on(
                                'scoped_enrollments.student_profile_id',
                                '=',
                                'scoped_submissions.student_profile_id'
                            );
                    })
                    ->whereColumn(
                        'scoped_submissions.id',
                        'assignment_evaluations.assignment_submission_id'
                    )
                    ->where('scoped_courses.teacher_profile_id', $teacherProfile->id)
                    ->whereIn(
                        'scoped_enrollments.status',
                        self::VALID_ENROLLMENT_STATUSES
                    );
            });

            return;
        }

        if ($user->hasRole('student')) {
            $studentProfile = $this->studentProfileFor($user);

            $query->whereHas(
                'assignmentSubmission',
                function (Builder $submissionQuery) use ($studentProfile) {
                    $submissionQuery->where(
                        'student_profile_id',
                        $studentProfile->id
                    );
                }
            );

            return;
        }

        if ($user->hasRole('parent')) {
            $query->whereHas(
                'assignmentSubmission',
                function (Builder $submissionQuery) use ($user) {
                    $this->parentStudentAccessService->scopeStudentRecords(
                        $submissionQuery,
                        $user,
                        'assignment_submissions.student_profile_id'
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
    private function authorizeEvaluationAccess(
        AssignmentEvaluation $assignmentEvaluation,
        ?User $user = null
    ): void {
        $user ??= Auth::user();

        $assignmentEvaluation->loadMissing([
            'assignmentSubmission.assignment.course',
            'assignmentSubmission.studentProfile',
        ]);

        $this->authorizeSubmissionAccess(
            $assignmentEvaluation->assignmentSubmission,
            $user,
            false
        );
    }

    private function authorizeEvaluationMutation(
        AssignmentEvaluation $assignmentEvaluation,
        ?User $user = null
    ): void {
        $user ??= Auth::user();

        if (! $user->hasAnyRole([
            'super-admin',
            'institution-admin',
            'teacher',
        ])) {
            abort(403, 'Unauthorized: Evaluations are read-only for your role.');
        }

        $assignmentEvaluation->loadMissing([
            'assignmentSubmission.assignment.course',
            'assignmentSubmission.studentProfile',
        ]);

        $this->authorizeSubmissionAccess(
            $assignmentEvaluation->assignmentSubmission,
            $user,
            true
        );
    }

    private function authorizeSubmissionAccess(
        AssignmentSubmission $submission,
        User $user,
        bool $isMutation,
        ?Collection $lockedEnrollments = null
    ): void {
        $submission->loadMissing([
            'assignment.course',
            'studentProfile',
        ]);

        $assignment = $submission->assignment;
        $course = $assignment?->course;
        $studentProfile = $submission->studentProfile;

        if (! $assignment || ! $course || ! $studentProfile) {
            abort(403, 'Unauthorized assignment submission.');
        }

        if ($user->hasRole('super-admin')) {
            return;
        }

        if (!$submission->hasConsistentInstitutions()) {
            abort(403, 'Course and student must belong to the same institution.');
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            if (
                (int) $course->institution_id ===
                (int) $institutionUser->institution_id &&
                (int) $studentProfile->institution_id ===
                (int) $institutionUser->institution_id &&
                (
                    ! $isMutation ||
                    $this->studentEnrolledInCourse(
                        $course->id,
                        $studentProfile->id,
                        $lockedEnrollments
                    )
                )
            ) {
                return;
            }

            abort(403, 'Unauthorized institution access.');
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = $this->teacherProfileFor($user);

            if (
                (int) $course->teacher_profile_id === (int) $teacherProfile->id &&
                $this->studentEnrolledInCourse(
                    $course->id,
                    $studentProfile->id,
                    $lockedEnrollments
                )
            ) {
                return;
            }

            abort(403, 'Unauthorized: Assignment is not from your enrolled course student.');
        }

        if ($user->hasRole('student')) {
            if ($isMutation) {
                abort(403, 'Unauthorized: Evaluations are read-only for students.');
            }

            $currentStudent = $this->studentProfileFor($user);

            if ((int) $studentProfile->id === (int) $currentStudent->id) {
                return;
            }

            abort(403, 'Unauthorized: You can only view your own evaluations.');
        }

        if ($user->hasRole('parent')) {
            if ($isMutation) {
                abort(403, 'Unauthorized: Evaluations are read-only for parents.');
            }

            $this->parentStudentAccessService->authorizeStudent(
                $user,
                $studentProfile,
                'Unauthorized: You can only view your child evaluations.'
            );

            return;
        }

        abort(403, 'Unauthorized role.');
    }

    /*
    |--------------------------------------------------------------------------
    | Grade Integrity
    |--------------------------------------------------------------------------
    */
    private function resolveMaximumMarks(
        mixed $requestedMaximumMarks,
        AssignmentSubmission $submission,
        User $user
    ): float {
        $assignmentMaximumMarks = (float) $submission->assignment->maximum_marks;

        if ($assignmentMaximumMarks <= 0) {
            $assignmentMaximumMarks = 100;
        }

        if ($user->hasRole('super-admin')) {
            return $requestedMaximumMarks !== null
                ? (float) $requestedMaximumMarks
                : $assignmentMaximumMarks;
        }

        if (
            $requestedMaximumMarks !== null &&
            abs((float) $requestedMaximumMarks - $assignmentMaximumMarks) > 0.001
        ) {
            throw ValidationException::withMessages([
                'maximum_marks' => 'Maximum marks must match the assignment maximum marks.',
            ]);
        }

        return $assignmentMaximumMarks;
    }

    private function ensureMarksWithinMaximum(
        float $marksObtained,
        float $maximumMarks
    ): void {
        if ($marksObtained <= $maximumMarks) {
            return;
        }

        throw ValidationException::withMessages([
            'marks_obtained' => 'Marks obtained cannot exceed maximum marks.',
        ]);
    }

    private function calculateResultStatus(
        float $marksObtained,
        float $maximumMarks
    ): string {
        $percentage = ($marksObtained / $maximumMarks) * 100;

        return match (true) {
            $percentage >= 50 => 'passed',
            $percentage >= 35 => 'needs_improvement',
            default => 'failed',
        };
    }

    private function resolveEvaluatorProfileId(
        mixed $requestedTeacherProfileId,
        AssignmentSubmission $submission,
        User $user
    ): ?int {
        if ($user->hasRole('teacher')) {
            return $this->teacherProfileFor($user)->id;
        }

        $courseTeacherProfileId = $submission->assignment->course->teacher_profile_id
            ?? $submission->assignment->teacher_profile_id;
        $teacherProfileId = $requestedTeacherProfileId ?? $courseTeacherProfileId;

        if (! $teacherProfileId || $user->hasRole('super-admin')) {
            return $teacherProfileId ? (int) $teacherProfileId : null;
        }

        $institutionUser = $this->institutionUserFor($user);
        $teacherProfile = TeacherProfile::findOrFail($teacherProfileId);

        if (
            (int) $teacherProfile->institution_id !==
            (int) $institutionUser->institution_id ||
            (
                $courseTeacherProfileId &&
                (int) $teacherProfile->id !== (int) $courseTeacherProfileId
            )
        ) {
            throw ValidationException::withMessages([
                'teacher_profile_id' => 'Evaluator must be the teacher assigned to this course.',
            ]);
        }

        return $teacherProfile->id;
    }

    private function studentEnrolledInCourse(
        int $courseId,
        int $studentProfileId,
        ?Collection $lockedEnrollments = null
    ): bool {
        if ($lockedEnrollments !== null) {
            $enrollment = $lockedEnrollments->first(
                fn (CourseEnrollment $candidate): bool =>
                    (int) $candidate->course_id === $courseId &&
                    (int) $candidate->student_profile_id === $studentProfileId
            );

            return $enrollment !== null &&
                ! $enrollment->trashed() &&
                in_array($enrollment->status, self::VALID_ENROLLMENT_STATUSES, true);
        }

        return CourseEnrollment::where('course_id', $courseId)
            ->institutionallyConsistent()
            ->where('student_profile_id', $studentProfileId)
            ->whereIn('status', self::VALID_ENROLLMENT_STATUSES)
            ->exists();
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
