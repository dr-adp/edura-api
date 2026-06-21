<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssignmentEvaluation;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Gradebook;
use App\Models\InstitutionUser;
use App\Models\ParentProfile;
use App\Models\QuizAttempt;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GradebookController extends Controller
{
    private const GRADEBOOK_RELATIONS = [
        'course',
        'studentProfile.user',
        'studentProfile.batch',
    ];

    private const VALID_ENROLLMENT_STATUSES = [
        'active',
        'completed',
    ];

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $gradebooks = Gradebook::with(self::GRADEBOOK_RELATIONS);

        /*
        |--------------------------------------------------------------------------
        | Scoped Gradebook Listing
        |--------------------------------------------------------------------------
        */
        $this->scopeGradebookQuery($gradebooks, $user);

        return response()->json([
            'message' => 'Gradebook records fetched successfully.',
            'data' => $gradebooks->latest()->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
        ]);

        // AUTHORIZATION: Verify course, student, and enrollment access
        $this->authorizeCourseStudentAccess(
            (int) $validated['course_id'],
            (int) $validated['student_profile_id']
        );

        $gradebook = $this->calculateGradebook(
            (int) $validated['course_id'],
            (int) $validated['student_profile_id']
        );

        return response()->json([
            'message' => 'Gradebook calculated successfully.',
            'data' => $gradebook->load(self::GRADEBOOK_RELATIONS),
        ], 201);
    }

    public function show(Gradebook $gradebook): JsonResponse
    {
        // AUTHORIZATION
        $this->authorizeGradebookAccess($gradebook);

        return response()->json([
            'message' => 'Gradebook record fetched successfully.',
            'data' => $gradebook->load(self::GRADEBOOK_RELATIONS),
        ]);
    }

    public function update(Request $request, Gradebook $gradebook): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        // AUTHORIZATION: Only authorized staff can update gradebooks
        $this->authorizeGradebookManagement($gradebook, $user);

        $validated = $request->validate([
            'assignment_marks' => ['nullable', 'numeric', 'min:0'],
            'quiz_marks' => ['nullable', 'numeric', 'min:0'],
            'maximum_marks' => ['nullable', 'numeric', 'min:0'],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Grade Tampering Protection
        |--------------------------------------------------------------------------
        */
        if (! $user->hasRole('super-admin')) {
            if ($validated !== []) {
                throw ValidationException::withMessages([
                    'gradebook' => 'Gradebook marks are calculated from assignment evaluations and quiz attempts.',
                ]);
            }

            $gradebook = $this->calculateGradebook(
                (int) $gradebook->course_id,
                (int) $gradebook->student_profile_id
            );

            return response()->json([
                'message' => 'Gradebook record updated successfully.',
                'data' => $gradebook->load(self::GRADEBOOK_RELATIONS),
            ]);
        }

        $assignmentMarks = $validated['assignment_marks'] ?? $gradebook->assignment_marks;
        $quizMarks = $validated['quiz_marks'] ?? $gradebook->quiz_marks;
        $maximumMarks = $validated['maximum_marks'] ?? $gradebook->maximum_marks;

        $totalMarks = $assignmentMarks + $quizMarks;

        if ($totalMarks > $maximumMarks) {
            throw ValidationException::withMessages([
                'maximum_marks' => 'Maximum marks cannot be less than total marks.',
            ]);
        }

        $percentage = $maximumMarks > 0
            ? round(($totalMarks / $maximumMarks) * 100, 2)
            : 0;

        $gradebook->update([
            'assignment_marks' => $assignmentMarks,
            'quiz_marks' => $quizMarks,
            'total_marks' => $totalMarks,
            'maximum_marks' => $maximumMarks,
            'percentage' => $percentage,
            'grade' => $this->calculateGrade($percentage),
            'result_status' => $percentage >= 40 ? 'passed' : 'failed',
        ]);

        return response()->json([
            'message' => 'Gradebook record updated successfully.',
            'data' => $gradebook->fresh()->load(self::GRADEBOOK_RELATIONS),
        ]);
    }

    public function destroy(Gradebook $gradebook): JsonResponse
    {
        // AUTHORIZATION: Only authorized staff can delete gradebooks
        $this->authorizeGradebookManagement($gradebook);

        $gradebook->delete();

        return response()->json([
            'message' => 'Gradebook record deleted successfully.',
        ]);
    }

    public function recalculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
        ]);

        // AUTHORIZATION: Verify course, student, and enrollment access
        $this->authorizeCourseStudentAccess(
            (int) $validated['course_id'],
            (int) $validated['student_profile_id']
        );

        $gradebook = $this->calculateGradebook(
            (int) $validated['course_id'],
            (int) $validated['student_profile_id']
        );

        return response()->json([
            'message' => 'Gradebook recalculated successfully.',
            'data' => $gradebook->load(self::GRADEBOOK_RELATIONS),
        ]);
    }

    private function calculateGradebook(int $courseId, int $studentProfileId): Gradebook
    {
        $course = Course::findOrFail($courseId);
        $student = StudentProfile::findOrFail($studentProfileId);

        if ((int) $course->institution_id !== (int) $student->institution_id) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'Student and Course must belong to the same institution.',
            ]);
        }

        $this->ensureStudentEnrolledInCourse($courseId, $studentProfileId);

        $assignmentEvaluations = AssignmentEvaluation::whereHas(
            'assignmentSubmission',
            function ($query) use ($courseId, $studentProfileId) {
                $query->where('student_profile_id', $studentProfileId)
                    ->whereHas('assignment', function ($assignmentQuery) use ($courseId) {
                        $assignmentQuery->where('course_id', $courseId);
                    });
            }
        )->get();

        $assignmentMarks = $assignmentEvaluations->sum('marks_obtained');
        $assignmentMaximumMarks = $assignmentEvaluations->sum('maximum_marks');

        $quizAttempts = QuizAttempt::where('student_profile_id', $studentProfileId)
            ->where('status', 'evaluated')
            ->whereHas('quiz', function ($query) use ($courseId) {
                $query->where('course_id', $courseId);
            })
            ->get();

        $quizMarks = $quizAttempts->sum('marks_obtained');
        $quizMaximumMarks = $quizAttempts->sum('total_marks');

        $totalMarks = $assignmentMarks + $quizMarks;
        $maximumMarks = $assignmentMaximumMarks + $quizMaximumMarks;

        $percentage = $maximumMarks > 0
            ? round(($totalMarks / $maximumMarks) * 100, 2)
            : 0;

        return Gradebook::updateOrCreate(
            [
                'course_id' => $courseId,
                'student_profile_id' => $studentProfileId,
            ],
            [
                'assignment_marks' => $assignmentMarks,
                'quiz_marks' => $quizMarks,
                'total_marks' => $totalMarks,
                'maximum_marks' => $maximumMarks,
                'percentage' => $percentage,
                'grade' => $this->calculateGrade($percentage),
                'result_status' => $maximumMarks > 0
                    ? ($percentage >= 40 ? 'passed' : 'failed')
                    : 'pending',
            ]
        );
    }

    private function calculateGrade(float $percentage): string
    {
        return match (true) {
            $percentage >= 90 => 'A+',
            $percentage >= 80 => 'A',
            $percentage >= 70 => 'B+',
            $percentage >= 60 => 'B',
            $percentage >= 50 => 'C',
            $percentage >= 40 => 'D',
            default => 'F',
        };
    }

    /**
     * Scope gradebook listings to the authenticated user's ownership boundary.
     */
    private function scopeGradebookQuery(Builder $gradebooks, User $user): void
    {
        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            $gradebooks
                ->whereHas('course', function (Builder $query) use ($institutionUser) {
                    $query->where('institution_id', $institutionUser->institution_id);
                })
                ->whereHas('studentProfile', function (Builder $query) use ($institutionUser) {
                    $query->where('institution_id', $institutionUser->institution_id);
                });

            return;
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = $this->teacherProfileFor($user);

            $gradebooks
                ->whereHas('course', function (Builder $query) use ($teacherProfile) {
                    $query->where('teacher_profile_id', $teacherProfile->id);
                })
                ->whereExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('course_enrollments')
                        ->whereColumn('course_enrollments.course_id', 'gradebooks.course_id')
                        ->whereColumn('course_enrollments.student_profile_id', 'gradebooks.student_profile_id')
                        ->whereIn('course_enrollments.status', self::VALID_ENROLLMENT_STATUSES);
                });

            return;
        }

        if ($user->hasRole('student')) {
            $studentProfile = $this->studentProfileFor($user);

            $gradebooks->where('student_profile_id', $studentProfile->id);

            return;
        }

        if ($user->hasRole('parent')) {
            $parentProfile = $this->parentProfileFor($user);

            $gradebooks->where('student_profile_id', $parentProfile->student_profile_id);

            return;
        }

        abort(403, 'Unauthorized.');
    }

    /**
     * Authorize access to view a gradebook record.
     */
    private function authorizeGradebookAccess(Gradebook $gradebook, ?User $user = null): void
    {
        $user ??= Auth::user();

        $gradebook->loadMissing([
            'course',
            'studentProfile',
        ]);

        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            if ($this->institutionCanAccessGradebook($gradebook, $institutionUser)) {
                return;
            }

            abort(403, 'Unauthorized: Gradebook does not belong to your institution.');
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = $this->teacherProfileFor($user);

            if ($this->teacherCanAccessGradebook($gradebook, $teacherProfile)) {
                return;
            }

            abort(403, 'Unauthorized: This gradebook does not belong to your course enrollment.');
        }

        if ($user->hasRole('student')) {
            $studentProfile = $this->studentProfileFor($user);

            if ($studentProfile && (int) $studentProfile->id === (int) $gradebook->student_profile_id) {
                return;
            }

            abort(403, 'Unauthorized: You can only view your own gradebook.');
        }

        if ($user->hasRole('parent')) {
            $parentProfile = $this->parentProfileFor($user);

            if ($parentProfile && (int) $parentProfile->student_profile_id === (int) $gradebook->student_profile_id) {
                return;
            }

            abort(403, 'Unauthorized: You can only view your child\'s gradebook.');
        }

        abort(403, 'Unauthorized.');
    }

    /**
     * Authorize access to create, update, delete, or recalculate a gradebook.
     */
    private function authorizeGradebookManagement(Gradebook $gradebook, ?User $user = null): void
    {
        $user ??= Auth::user();

        if (! $user->hasAnyRole([
            'super-admin',
            'institution-admin',
            'teacher',
        ])) {
            abort(403, 'Unauthorized: You do not have permission to manage this gradebook.');
        }

        $this->authorizeGradebookAccess($gradebook, $user);
    }

    /**
     * Authorize that the authenticated user has access to this course/student pair.
     */
    private function authorizeCourseStudentAccess(int $courseId, int $studentProfileId): void
    {
        /** @var User $user */
        $user = Auth::user();

        $course = Course::findOrFail($courseId);
        $studentProfile = StudentProfile::findOrFail($studentProfileId);

        if ($user->hasRole('super-admin')) {
            $this->ensureStudentEnrolledInCourse($courseId, $studentProfileId);

            return;
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = $this->institutionUserFor($user);

            if (
                (int) $course->institution_id === (int) $institutionUser->institution_id &&
                (int) $studentProfile->institution_id === (int) $institutionUser->institution_id
            ) {
                $this->ensureStudentEnrolledInCourse($courseId, $studentProfileId);

                return;
            }

            abort(403, 'Unauthorized: Course or student does not belong to your institution.');
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = $this->teacherProfileFor($user);

            if ((int) $course->teacher_profile_id === (int) $teacherProfile->id) {
                $this->ensureStudentEnrolledInCourse($courseId, $studentProfileId);

                return;
            }

            abort(403, 'Unauthorized: This course is not assigned to you.');
        }

        abort(403, 'Unauthorized: You do not have permission to manage this gradebook.');
    }

    private function institutionCanAccessGradebook(
        Gradebook $gradebook,
        InstitutionUser $institutionUser
    ): bool {
        $gradebook->loadMissing([
            'course',
            'studentProfile',
        ]);

        return $gradebook->course &&
            $gradebook->studentProfile &&
            (int) $gradebook->course->institution_id === (int) $institutionUser->institution_id &&
            (int) $gradebook->studentProfile->institution_id === (int) $institutionUser->institution_id;
    }

    private function teacherCanAccessGradebook(
        Gradebook $gradebook,
        TeacherProfile $teacherProfile
    ): bool {
        $gradebook->loadMissing([
            'course',
        ]);

        return $gradebook->course &&
            (int) $gradebook->course->teacher_profile_id === (int) $teacherProfile->id &&
            $this->studentEnrolledInCourse(
                (int) $gradebook->course_id,
                (int) $gradebook->student_profile_id
            );
    }

    private function ensureStudentEnrolledInCourse(int $courseId, int $studentProfileId): void
    {
        if ($this->studentEnrolledInCourse($courseId, $studentProfileId)) {
            return;
        }

        throw ValidationException::withMessages([
            'student_profile_id' => 'Student is not enrolled in this course.',
        ]);
    }

    private function studentEnrolledInCourse(int $courseId, int $studentProfileId): bool
    {
        return CourseEnrollment::where('course_id', $courseId)
            ->where('student_profile_id', $studentProfileId)
            ->whereIn('status', self::VALID_ENROLLMENT_STATUSES)
            ->exists();
    }

    private function institutionUserFor(User $user): InstitutionUser
    {
        $institutionUser = InstitutionUser::where('user_id', $user->id)->first();

        if ($institutionUser) {
            return $institutionUser;
        }

        abort(403, 'Unauthorized: Institution profile not found.');
    }

    private function teacherProfileFor(User $user): TeacherProfile
    {
        $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();

        if ($teacherProfile) {
            return $teacherProfile;
        }

        abort(403, 'Unauthorized: Teacher profile not found.');
    }

    private function studentProfileFor(User $user): StudentProfile
    {
        $studentProfile = StudentProfile::where('user_id', $user->id)->first();

        if ($studentProfile) {
            return $studentProfile;
        }

        abort(403, 'Unauthorized: Student profile not found.');
    }

    private function parentProfileFor(User $user): ParentProfile
    {
        $parentProfile = ParentProfile::where('user_id', $user->id)->first();

        if ($parentProfile && $parentProfile->student_profile_id) {
            return $parentProfile;
        }

        abort(403, 'Unauthorized: Parent profile not found.');
    }
}
