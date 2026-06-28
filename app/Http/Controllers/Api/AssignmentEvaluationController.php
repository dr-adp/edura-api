<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\AssignmentEvaluation;
use App\Models\AssignmentSubmission;
use App\Models\CourseEnrollment;
use App\Models\InstitutionUser;
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

class AssignmentEvaluationController extends BaseApiController
{
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

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'assignment_submission_id' => [
                'required',
                'exists:assignment_submissions,id',
                Rule::unique('assignment_evaluations', 'assignment_submission_id'),
            ],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],
            'marks_obtained' => ['required', 'numeric', 'min:0'],
            'maximum_marks' => ['nullable', 'numeric', 'min:1'],
            'feedback' => ['nullable', 'string'],
            'result_status' => ['nullable', 'in:passed,failed,needs_improvement'],
        ]);

        $submission = AssignmentSubmission::with([
            'assignment.course',
            'studentProfile',
        ])->findOrFail($validated['assignment_submission_id']);

        /*
        |--------------------------------------------------------------------------
        | Ownership And Grade Integrity Check
        |--------------------------------------------------------------------------
        */
        $this->authorizeSubmissionAccess($submission, $user, true);

        $maximumMarks = $this->resolveMaximumMarks(
            $validated['maximum_marks'] ?? null,
            $submission,
            $user
        );
        $marksObtained = (float) $validated['marks_obtained'];

        $this->ensureMarksWithinMaximum($marksObtained, $maximumMarks);

        $validated['teacher_profile_id'] = $this->resolveEvaluatorProfileId(
            $validated['teacher_profile_id'] ?? null,
            $submission,
            $user
        );
        $validated['maximum_marks'] = $maximumMarks;
        $validated['result_status'] = $this->calculateResultStatus(
            $marksObtained,
            $maximumMarks
        );
        $validated['evaluated_at'] = now();

        $evaluation = DB::transaction(function () use ($validated, $submission) {
            $evaluation = AssignmentEvaluation::create($validated);

            $submission->update([
                'status' => 'reviewed',
            ]);

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
        Request $request,
        AssignmentEvaluation $assignmentEvaluation
    ): JsonResponse {
        /** @var User $user */
        $user = Auth::user();

        $this->authorizeEvaluationMutation($assignmentEvaluation, $user);

        $validated = $request->validate([
            'assignment_submission_id' => [
                'sometimes',
                'exists:assignment_submissions,id',
                Rule::unique('assignment_evaluations', 'assignment_submission_id')
                    ->ignore($assignmentEvaluation->id),
            ],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],
            'marks_obtained' => ['sometimes', 'numeric', 'min:0'],
            'maximum_marks' => ['nullable', 'numeric', 'min:1'],
            'feedback' => ['nullable', 'string'],
            'result_status' => ['nullable', 'in:passed,failed,needs_improvement'],
        ]);

        $originalSubmission = $assignmentEvaluation->assignmentSubmission;
        $targetSubmissionId = $validated['assignment_submission_id']
            ?? $assignmentEvaluation->assignment_submission_id;
        $targetSubmission = AssignmentSubmission::with([
            'assignment.course',
            'studentProfile',
        ])->findOrFail($targetSubmissionId);

        /*
        |--------------------------------------------------------------------------
        | Target Ownership And Grade Integrity Check
        |--------------------------------------------------------------------------
        */
        $this->authorizeSubmissionAccess($targetSubmission, $user, true);

        $requestedMaximumMarks = array_key_exists('maximum_marks', $validated)
            ? $validated['maximum_marks']
            : ($user->hasRole('super-admin')
                ? $assignmentEvaluation->maximum_marks
                : null);

        $maximumMarks = $this->resolveMaximumMarks(
            $requestedMaximumMarks,
            $targetSubmission,
            $user
        );
        $marksObtained = (float) (
            $validated['marks_obtained'] ?? $assignmentEvaluation->marks_obtained
        );

        $this->ensureMarksWithinMaximum($marksObtained, $maximumMarks);

        $validated['teacher_profile_id'] = $this->resolveEvaluatorProfileId(
            $validated['teacher_profile_id'] ?? $assignmentEvaluation->teacher_profile_id,
            $targetSubmission,
            $user
        );
        $validated['maximum_marks'] = $maximumMarks;
        $validated['result_status'] = $this->calculateResultStatus(
            $marksObtained,
            $maximumMarks
        );
        $validated['evaluated_at'] = now();

        DB::transaction(function () use (
            $assignmentEvaluation,
            $validated,
            $originalSubmission,
            $targetSubmission
        ) {
            $assignmentEvaluation->update($validated);

            $targetSubmission->update([
                'status' => 'reviewed',
            ]);

            if ((int) $originalSubmission->id !== (int) $targetSubmission->id) {
                $originalSubmission->update([
                    'status' => 'submitted',
                ]);
            }
        });

        return $this->successResponse(
            $assignmentEvaluation->fresh()->load(self::EVALUATION_RELATIONS),
            'Assignment evaluation updated successfully.'
        );
    }

    public function destroy(AssignmentEvaluation $assignmentEvaluation): JsonResponse
    {
        $this->authorizeEvaluationMutation($assignmentEvaluation);

        $submission = $assignmentEvaluation->assignmentSubmission;

        DB::transaction(function () use ($assignmentEvaluation, $submission) {
            $assignmentEvaluation->delete();

            $submission?->update([
                'status' => 'submitted',
            ]);
        });

        return $this->successResponse(
            null,
            'Assignment evaluation deleted successfully.'
        );
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
            $parentProfile = $this->parentProfileFor($user);

            $query->whereHas(
                'assignmentSubmission',
                function (Builder $submissionQuery) use ($parentProfile) {
                    $submissionQuery->where(
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
        bool $isMutation
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

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            if (
                (int) $course->institution_id ===
                (int) $institutionUser->institution_id &&
                (int) $studentProfile->institution_id ===
                (int) $institutionUser->institution_id &&
                (
                    ! $isMutation ||
                    $this->studentEnrolledInCourse($course->id, $studentProfile->id)
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
                $this->studentEnrolledInCourse($course->id, $studentProfile->id)
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

            $parentProfile = $this->parentProfileFor($user);

            if (
                (int) $studentProfile->id ===
                (int) $parentProfile->student_profile_id
            ) {
                return;
            }

            abort(403, 'Unauthorized: You can only view your child evaluations.');
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

    private function studentEnrolledInCourse(int $courseId, int $studentProfileId): bool
    {
        return CourseEnrollment::where('course_id', $courseId)
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
