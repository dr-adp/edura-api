<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AssessmentLockSetChanged;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\StudentProfile;
use App\Services\CourseAssessmentLockService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\InstitutionUser;
use App\Services\ParentStudentAccessService;
use App\Http\Requests\StoreAssignmentSubmissionRequest;
use App\Http\Requests\UpdateAssignmentSubmissionRequest;
use Illuminate\Support\Collection;
use Throwable;

class AssignmentSubmissionController extends BaseApiController
{
    public function __construct(
        private ParentStudentAccessService $parentStudentAccessService,
        private CourseAssessmentLockService $courseAssessmentLockService
    ) {
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $query = AssignmentSubmission::with([
            'assignment.course',
            'studentProfile.user',
            'studentProfile.batch'
        ])->latest();

        if (!$user->hasRole('super-admin')) {
            $query->institutionallyConsistent();
        }

        /*
|--------------------------------------------------------------------------
| Student Role
|--------------------------------------------------------------------------
*/
        if ($user->hasRole('student')) {

            $studentProfile = $user->studentProfile;

            if (!$studentProfile) {

                abort(
                    403,
                    'Unauthorized: Student profile not found.'
                );
            }

            $query->where(
                'student_profile_id',
                $studentProfile->id
            );
        }

        /*
|--------------------------------------------------------------------------
| Institution Admin Role
|--------------------------------------------------------------------------
*/
        if ($user->hasRole('institution-admin')) {

            $institutionUser = $this->institutionUserFor($user);

            $query->whereHas(
                'studentProfile',
                function ($q) use ($institutionUser) {

                    $q->where(
                        'institution_id',
                        $institutionUser->institution_id
                    );
                }
            )->whereHas(
                'assignment.course',
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
| Teacher Role
|--------------------------------------------------------------------------
*/
        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Unauthorized: Teacher profile not found.'
                );
            }

            $query->whereHas(
                'assignment',
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
| Parent Role
|--------------------------------------------------------------------------
*/
        if ($user->hasRole('parent')) {

            $this->parentStudentAccessService->scopeStudentRecords(
                $query,
                $user,
                'assignment_submissions.student_profile_id'
            );
        }

        /*
|--------------------------------------------------------------------------
| Unknown Role Protection
|--------------------------------------------------------------------------
*/
        if (
            !$user->hasAnyRole([
                'super-admin',
                'institution-admin',
                'teacher',
                'student',
                'parent'
            ])
        ) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        $submissions = $query->paginate(20);

        return $this->successResponse(
            $submissions,
            'Assignment submissions fetched successfully.'
        );
    }

    public function store(StoreAssignmentSubmissionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        /*
|--------------------------------------------------------------------------
| Role Protection
|--------------------------------------------------------------------------
*/
        if (
            !$user->hasAnyRole([
                'super-admin',
                'student'
            ])
        ) {

            abort(
                403,
                'Unauthorized role.'
            );
        }
        $validated = $request->validated();
        // The file is stored only after the locked checks pass below.

        unset($validated['file']);

        $storedFilePath = null;

        try {
            $submission = $this->courseAssessmentLockService
                ->transactionWithLockSetRetries(function () use (
                    $request,
                    $validated,
                    $user,
                    &$storedFilePath
                ): AssignmentSubmission {
                    $context = $this->lockSubmissionWriteContext(
                        (int) $validated['assignment_id'],
                        (int) $validated['student_profile_id']
                    );
                    /** @var Assignment $lockedAssignment */
                    $lockedAssignment = $context['assignment'];
                    /** @var StudentProfile $lockedStudent */
                    $lockedStudent = $context['student'];

                    if ($user->hasRole('student')) {
                        if ((int) $lockedStudent->user_id !== (int) $user->id) {
                            abort(403, 'You can only submit your own assignments.');
                        }

                        if (
                            $lockedAssignment->course->trashed() ||
                            (int) $lockedAssignment->course->institution_id !==
                            (int) $lockedStudent->institution_id
                        ) {
                            abort(403, 'Course and student must belong to the same institution.');
                        }

                        if (! $this->hasEligibleEnrollment(
                            $context['enrollment'],
                            $lockedAssignment,
                            $lockedStudent
                        )) {
                            abort(403, 'You are not enrolled in this course.');
                        }

                        if (
                            $lockedAssignment->due_date &&
                            now()->greaterThan($lockedAssignment->due_date) &&
                            ! $lockedAssignment->allow_late_submission
                        ) {
                            abort(403, 'Submission deadline has passed.');
                        }
                    }

                    $existingSubmission = $context['existing_submission'];

                    if ($existingSubmission) {
                        if ($existingSubmission->status === 'reviewed') {
                            abort(403, 'Assignment has already been reviewed.');
                        }

                        abort(403, 'Assignment has already been submitted.');
                    }

                    $attributes = $validated;

                    if ($request->hasFile('file')) {
                        $storedFilePath = $request->file('file')->store(
                            'assignment-submissions',
                            'public'
                        );
                        $attributes['file_path'] = $storedFilePath;
                    }

                    if (($attributes['status'] ?? null) === 'submitted') {
                        $attributes['submitted_at'] = now();
                        $attributes['is_late'] = $this->checkIfLate($lockedAssignment);
                    }

                    return AssignmentSubmission::create($attributes);
                });
        } catch (Throwable $exception) {
            if ($storedFilePath !== null) {
                Storage::disk('public')->delete($storedFilePath);
            }

            throw $exception;
        }

        return $this->successResponse(
            $submission->load([
                'assignment.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
            'Assignment submission created successfully.',
            201
        );
    }

    public function show(
        AssignmentSubmission $assignmentSubmission
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        $assignmentSubmission->load([
            'assignment.course',
            'studentProfile.user',
            'studentProfile.batch'
        ]);

        $this->ensureSubmissionInstitutionalConsistency($assignmentSubmission, $user);

        /*
    |--------------------------------------------------------------------------
    | Student Role
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('student')) {

            $studentProfile = $user->studentProfile;

            if (
                !$studentProfile ||
                $assignmentSubmission->student_profile_id !== $studentProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized: You can only view your own submissions.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Parent Role
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('parent')) {

            $this->parentStudentAccessService->authorizeStudent(
                $user,
                (int) $assignmentSubmission->student_profile_id,
                'Unauthorized: You can only view your child submissions.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Institution Admin Role
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('institution-admin')) {

            $institutionUser = $this->institutionUserFor($user);

            if (
                (int) $assignmentSubmission->studentProfile->institution_id !==
                    (int) $institutionUser->institution_id ||
                (int) $assignmentSubmission->assignment->course->institution_id !==
                    (int) $institutionUser->institution_id
            ) {

                abort(
                    403,
                    'Unauthorized: Student does not belong to your institution.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher Role
    |--------------------------------------------------------------------------
    */
        if ($user->hasRole('teacher')) {

            $teacherProfile = $user->teacherProfile;

            if (!$teacherProfile) {

                abort(
                    403,
                    'Unauthorized: Teacher profile not found.'
                );
            }

            if (
                $assignmentSubmission
                ->assignment
                ->teacher_profile_id !== $teacherProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized: This submission does not belong to your assignments.'
                );
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Unknown Role Protection
    |--------------------------------------------------------------------------
    */
        if (
            !$user->hasAnyRole([
                'super-admin',
                'institution-admin',
                'teacher',
                'student',
                'parent'
            ])
        ) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        return $this->successResponse(
            $assignmentSubmission,
            'Assignment submission fetched successfully.'
        );
    }

    public function update(
        UpdateAssignmentSubmissionRequest $request,
        AssignmentSubmission $assignmentSubmission
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Role Protection
    |--------------------------------------------------------------------------
    */
        if (
            !$user->hasAnyRole([
                'super-admin',
                'student'
            ])
        ) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
        $validated = $request->validated();
        $submissionId = (int) $assignmentSubmission->id;
        $storedFilePath = null;
        $previousFilePath = null;

        try {
            $updatedSubmission = $this->courseAssessmentLockService
                ->transactionWithLockSetRetries(function () use (
                    $request,
                    $validated,
                    $submissionId,
                    $user,
                    &$storedFilePath,
                    &$previousFilePath
                ): AssignmentSubmission {
                    $context = $this->lockSubmissionMutationContext($submissionId);
                    $lockedSubmission = $context['submission'];
                    $lockedAssignment = $context['assignment'];
                    $lockedStudent = $context['student'];

                    $this->authorizeLockedSubmissionMutation(
                        $lockedSubmission,
                        $lockedAssignment,
                        $lockedStudent,
                        $context['enrollment'],
                        $user,
                        'update'
                    );

                    $activeEvaluation = $context['evaluations']->first(
                        static fn ($evaluation): bool => ! $evaluation->trashed()
                    );
                    if ($lockedSubmission->status === 'reviewed' || $activeEvaluation) {
                        abort(403, 'Reviewed assignments cannot be modified.');
                    }

                    if (
                        $user->hasRole('student') &&
                        $lockedAssignment->due_date &&
                        now()->greaterThan($lockedAssignment->due_date) &&
                        ! $lockedAssignment->allow_late_submission
                    ) {
                        abort(403, 'Submission deadline has passed.');
                    }

                    $attributes = $validated;
                    if ($request->hasFile('file')) {
                        $previousFilePath = $lockedSubmission->file_path;
                        $storedFilePath = $request->file('file')->store(
                            'assignment-submissions',
                            'public'
                        );
                        $attributes['file_path'] = $storedFilePath;
                    }

                    if (
                        ($attributes['status'] ?? null) === 'submitted' &&
                        ! $lockedSubmission->submitted_at
                    ) {
                        $attributes['submitted_at'] = now();
                        $attributes['is_late'] = $this->checkIfLate($lockedAssignment);
                    }

                    $lockedSubmission->fill($attributes);
                    $lockedSubmission->save();

                    if ($storedFilePath !== null && $previousFilePath !== null) {
                        DB::afterCommit(function () use ($previousFilePath): void {
                            if (Storage::disk('public')->exists($previousFilePath)) {
                                Storage::disk('public')->delete($previousFilePath);
                            }
                        });
                    }

                    return $lockedSubmission;
                });
        } catch (Throwable $exception) {
            if ($storedFilePath !== null) {
                Storage::disk('public')->delete($storedFilePath);
            }

            throw $exception;
        }

        return $this->successResponse(
            $updatedSubmission
                ->fresh()
                ->load([
                    'assignment.course',
                    'studentProfile.user',
                    'studentProfile.batch'
                ]),
            'Assignment submission updated successfully.'
        );
    }

    public function destroy(
        AssignmentSubmission $assignmentSubmission
    ): JsonResponse {

        /** @var User $user */
        $user = Auth::user();

        /*
    |--------------------------------------------------------------------------
    | Role Protection
    |--------------------------------------------------------------------------
    */
        if (
            !$user->hasAnyRole([
                'super-admin',
                'student'
            ])
        ) {

            abort(
                403,
                'Unauthorized role.'
            );
        }

        $submissionId = (int) $assignmentSubmission->id;
        $this->courseAssessmentLockService->transactionWithLockSetRetries(
            function () use ($submissionId, $user): void {
                $context = $this->lockSubmissionMutationContext($submissionId);
                $lockedSubmission = $context['submission'];
                $lockedAssignment = $context['assignment'];
                $lockedStudent = $context['student'];

                $this->authorizeLockedSubmissionMutation(
                    $lockedSubmission,
                    $lockedAssignment,
                    $lockedStudent,
                    $context['enrollment'],
                    $user,
                    'delete'
                );

                $activeEvaluation = $context['evaluations']->first(
                    static fn ($evaluation): bool => ! $evaluation->trashed()
                );
                if (
                    in_array($lockedSubmission->status, ['submitted', 'reviewed'], true) ||
                    $activeEvaluation
                ) {
                    abort(403, 'Submitted or reviewed assignments cannot be deleted.');
                }

                $filePath = $lockedSubmission->file_path;
                $lockedSubmission->delete();

                if ($filePath !== null) {
                    DB::afterCommit(function () use ($filePath): void {
                        if (Storage::disk('public')->exists($filePath)) {
                            Storage::disk('public')->delete($filePath);
                        }
                    });
                }
            }
        );

        return $this->successResponse(
            null,
            'Assignment submission deleted successfully.'
        );
    }

    /**
     * Lock the parent rows and the unique assignment/student submission pair
     * before creating a submission. The assignment/course identities are
     * revalidated after their rows are locked.
     *
     * @return array{
     *     course:Course,
     *     student:StudentProfile,
     *     enrollment:?CourseEnrollment,
     *     assignment:Assignment,
     *     existing_submission:?AssignmentSubmission
     * }
     */
    private function lockSubmissionWriteContext(
        int $assignmentId,
        int $studentProfileId
    ): array {
        $assignmentIdentity = Assignment::withTrashed()->whereKey($assignmentId)->firstOrFail();
        $courseId = (int) $assignmentIdentity->course_id;
        $course = $this->courseAssessmentLockService->lockCourse($courseId);
        $student = $this->courseAssessmentLockService->lockStudent($studentProfileId);
        $enrollment = $this->courseAssessmentLockService->lockCourseEnrollments([[
            'course_id' => $courseId,
            'student_profile_id' => $studentProfileId,
        ]])->first();
        $assignment = $this->courseAssessmentLockService
            ->lockAssignments([$assignmentId])
            ->first();

        if (! $assignment || $assignment->trashed()) {
            abort(404);
        }

        if ((int) $assignment->course_id !== $courseId) {
            throw new AssessmentLockSetChanged([$courseId, (int) $assignment->course_id]);
        }

        $assignment->setRelation('course', $course);
        $existingSubmission = $this->courseAssessmentLockService
            ->lockAssignmentSubmissionForPair($assignmentId, $studentProfileId);

        return [
            'course' => $course,
            'student' => $student,
            'enrollment' => $enrollment,
            'assignment' => $assignment,
            'existing_submission' => $existingSubmission,
        ];
    }

    /**
     * Lock a submission's complete relationship chain before mutation.
     * Preliminary reads discover the lock set only; all identities are
     * checked again after the corresponding rows have been locked.
     *
     * @return array{
     *     course:Course,
     *     student:StudentProfile,
     *     enrollment:?CourseEnrollment,
     *     assignment:Assignment,
     *     submission:AssignmentSubmission,
     *     evaluations:Collection<int, \App\Models\AssignmentEvaluation>
     * }
     */
    private function lockSubmissionMutationContext(int $submissionId): array
    {
        $submissionIdentity = AssignmentSubmission::withTrashed()
            ->whereKey($submissionId)
            ->firstOrFail();
        $assignmentId = (int) $submissionIdentity->assignment_id;
        $studentProfileId = (int) $submissionIdentity->student_profile_id;
        $assignmentIdentity = Assignment::withTrashed()->whereKey($assignmentId)->firstOrFail();
        $courseId = (int) $assignmentIdentity->course_id;

        $course = $this->courseAssessmentLockService->lockCourse($courseId);
        $student = $this->courseAssessmentLockService->lockStudent($studentProfileId);
        $enrollment = $this->courseAssessmentLockService->lockCourseEnrollments([[
            'course_id' => $courseId,
            'student_profile_id' => $studentProfileId,
        ]])->first();
        $assignment = $this->courseAssessmentLockService
            ->lockAssignments([$assignmentId])
            ->first();

        if (! $assignment) {
            abort(404);
        }

        if ((int) $assignment->course_id !== $courseId) {
            throw new AssessmentLockSetChanged([$courseId, (int) ($assignment?->course_id ?? 0)]);
        }

        $submission = $this->courseAssessmentLockService
            ->lockAssignmentSubmissions([$submissionId])
            ->first();

        if (! $submission || $submission->trashed()) {
            abort(404);
        }

        if (
            (int) $submission->assignment_id !== $assignmentId ||
            (int) $submission->student_profile_id !== $studentProfileId
        ) {
            throw new AssessmentLockSetChanged([$courseId], [$studentProfileId]);
        }

        $evaluations = $this->courseAssessmentLockService
            ->lockAssignmentEvaluationsForSubmissions([$submissionId]);

        $assignment->setRelation('course', $course);
        $submission->setRelation('assignment', $assignment);
        $submission->setRelation('studentProfile', $student);

        return [
            'course' => $course,
            'student' => $student,
            'enrollment' => $enrollment,
            'assignment' => $assignment,
            'submission' => $submission,
            'evaluations' => $evaluations,
        ];
    }

    private function authorizeLockedSubmissionMutation(
        AssignmentSubmission $submission,
        Assignment $assignment,
        StudentProfile $student,
        ?CourseEnrollment $enrollment,
        User $user,
        string $operation
    ): void {
        $course = $assignment->course;

        if (
            ! $user->hasRole('super-admin') &&
            (
                $assignment->trashed() ||
                ! $course ||
                $course->trashed() ||
                $course->institution_id === null ||
                $student->institution_id === null ||
                (int) $course->institution_id !== (int) $student->institution_id
            )
        ) {
            abort(403, 'Course and student must belong to the same institution.');
        }

        if ($user->hasRole('student')) {
            if ((int) $student->user_id !== (int) $user->id) {
                abort(
                    403,
                    $operation === 'delete'
                        ? 'You can delete only your own submission.'
                        : 'You can update only your own submission.'
                );
            }

            if (! $this->hasEligibleEnrollment($enrollment, $assignment, $student)) {
                abort(403, 'You are not enrolled in this course.');
            }
        }
    }

    private function hasEligibleEnrollment(
        ?CourseEnrollment $enrollment,
        Assignment $assignment,
        StudentProfile $student
    ): bool {
        $course = $assignment->course;

        return $enrollment !== null &&
            ! $enrollment->trashed() &&
            (int) $enrollment->course_id === (int) $assignment->course_id &&
            (int) $enrollment->student_profile_id === (int) $student->id &&
            in_array($enrollment->status, ['active', 'completed'], true) &&
            $course !== null &&
            ! $course->trashed() &&
            $course->institution_id !== null &&
            $student->institution_id !== null &&
            (int) $course->institution_id === (int) $student->institution_id;
    }

    private function checkIfLate(Assignment $assignment): bool
    {
        $dueDate = $assignment->due_date;

        return $dueDate
            ? now()->greaterThan($dueDate)
            : false;
    }

    private function ensureSubmissionInstitutionalConsistency(
        AssignmentSubmission $assignmentSubmission,
        User $user
    ): void {
        if (
            !$user->hasRole('super-admin') &&
            !$assignmentSubmission->hasConsistentInstitutions()
        ) {
            abort(403, 'Course and student must belong to the same institution.');
        }
    }

    private function institutionUserFor(User $user): InstitutionUser
    {
        $institutionUser = InstitutionUser::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($institutionUser) {
            return $institutionUser;
        }

        abort(403, 'Active institution membership required.');
    }
}
