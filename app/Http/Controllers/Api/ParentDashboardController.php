<?php

namespace App\Http\Controllers\Api;


use App\Models\ParentProfile;
use App\Models\StudentProfile;
use App\Models\CourseEnrollment;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceRecord;
use App\Models\Gradebook;
use App\Models\LiveClass;
use App\Models\QuizAttempt;
use App\Models\Certificate;
use App\Services\ParentStudentAccessService;
use App\Services\QuizAttemptGradingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ParentDashboardController extends Controller
{
    public function __construct(
        private ParentStudentAccessService $parentStudentAccessService,
        private QuizAttemptGradingService $quizAttemptGradingService
    ) {
    }

    private function getParentProfileForChild(
        StudentProfile $studentProfile
    ): ParentProfile {
        /** @var \App\Models\User $parent */
        $parent = Auth::user();
        $parentProfile = $this->parentStudentAccessService
            ->validLinksFor($parent)
            ->where('parent_profiles.student_profile_id', $studentProfile->id)
            ->first();

        abort_unless(
            $parentProfile,
            403,
            'Unauthorized: This child is not linked to your active parent profile.'
        );

        return $parentProfile;
    }

    public function show(StudentProfile $studentProfile): JsonResponse
    {
        $parentProfile = $this->getParentProfileForChild($studentProfile);

        $parentProfile->load([
            'user',
            'institution',
            'studentProfile.user',
            'studentProfile.department',
            'studentProfile.batch',
        ]);

        $studentProfile = $parentProfile->studentProfile;

        $enrolledCourses = CourseEnrollment::where('student_profile_id', $studentProfile->id)
            ->institutionallyConsistent()
            ->with('course')
            ->get();

        $overallProgress = round($enrolledCourses->avg('progress_percentage') ?? 0, 2);

        $courseIds = $enrolledCourses->pluck('course_id');

        $pendingAssignments = Assignment::whereIn('course_id', $courseIds)
            ->whereDoesntHave('submissions', function ($query) use ($studentProfile) {
            $query->where('student_profile_id', $studentProfile->id);
        })
            ->with('course')
            ->get();

        $attendanceQuery = $this->consistentAttendanceFor($studentProfile);

        $recentAttendance = (clone $attendanceQuery)
            ->with('course')
            ->latest('attendance_date')
            ->take(10)
            ->get();

        $attendanceSummary = [
            'total' => (clone $attendanceQuery)->count(),
            'present' => (clone $attendanceQuery)
                ->where('attendance_status', 'present')->count(),
            'absent' => (clone $attendanceQuery)
                ->where('attendance_status', 'absent')->count(),
            'late' => (clone $attendanceQuery)
                ->where('attendance_status', 'late')->count(),
            'excused' => (clone $attendanceQuery)
                ->where('attendance_status', 'excused')->count(),
        ];

        $gradebookQuery = Gradebook::where('student_profile_id', $studentProfile->id)
            ->institutionallyConsistent()
            ->with('course');
        $this->quizAttemptGradingService
            ->withPendingManualGradingFlag($gradebookQuery);
        $gradebooks = $gradebookQuery->get();
        $gradebooks->each(function (Gradebook $gradebook) {
            $this->quizAttemptGradingService
                ->redactPendingGradebookResults($gradebook);
        });

        $upcomingLiveClasses = LiveClass::whereHas('course.enrollments', function ($query) use ($studentProfile) {
            $query->institutionallyConsistent()
                ->where('student_profile_id', $studentProfile->id);
        })
            ->where('institution_id', $studentProfile->institution_id)
            ->where('scheduled_start_time', '>=', now())
            ->with(['course', 'teacherProfile.user'])
            ->orderBy('scheduled_start_time')
            ->get();

        $quizAttemptQuery = QuizAttempt::where('student_profile_id', $studentProfile->id)
            ->institutionallyConsistent()
            ->with('quiz');
        $this->quizAttemptGradingService
            ->withPendingManualAnswersFlag($quizAttemptQuery);
        $quizAttempts = $quizAttemptQuery
            ->latest()
            ->get();
        $quizAttempts->each(function (QuizAttempt $attempt) {
            $this->quizAttemptGradingService
                ->redactPendingAttemptResults($attempt);
        });

        $certificateQuery = Certificate::where('student_profile_id', $studentProfile->id)
            ->whereHas('course', function ($query) use ($studentProfile) {
                $query->where('institution_id', $studentProfile->institution_id);
            })
            ->with(['course', 'gradebook']);
        $this->quizAttemptGradingService
            ->withPendingManualGradingFlagForCertificates($certificateQuery);
        $certificates = $certificateQuery
            ->latest('issued_date')
            ->get();
        $certificates->each(function (Certificate $certificate) {
            $this->hideInconsistentCertificateGradebook($certificate);
            $this->quizAttemptGradingService
                ->redactPendingCertificateResults($certificate);
        });

        return response()->json([
            'message' => 'Parent dashboard fetched successfully.',
            'data' => [
                'parent' => $parentProfile,
                'student' => $studentProfile,
                'enrolled_courses' => $enrolledCourses,
                'overall_progress' => $overallProgress,
                'pending_assignments' => $pendingAssignments,
                'recent_attendance' => $recentAttendance,
                'attendance_summary' => $attendanceSummary,
                'gradebooks' => $gradebooks,
                'upcoming_live_classes' => $upcomingLiveClasses,
                'quiz_attempts' => $quizAttempts,
                'certificates' => $certificates,
            ],
        ]);
    }

    public function children(): JsonResponse
    {
        /** @var \App\Models\User $parent */
        $parent = Auth::user();
        $childrenQuery = StudentProfile::query();
        $this->parentStudentAccessService->scopeStudentRecords(
            $childrenQuery,
            $parent,
            'student_profiles.id'
        );

        $children = $childrenQuery
            ->with(['user', 'department', 'batch', 'institution'])
            ->get()
            ->map(function ($student) {
                $enrollments = CourseEnrollment::where(
                    'student_profile_id',
                    $student->id
                )->institutionallyConsistent();

                $student->setAttribute(
                    'enrolled_courses_count',
                    (clone $enrollments)->count()
                );

                $student->setAttribute(
                    'completed_courses_count',
                    (clone $enrollments)
                        ->where('status', 'completed')
                        ->count()
                );

                $student->setAttribute(
                    'overall_progress',
                    round(
                        (clone $enrollments)->avg('progress_percentage') ?? 0,
                        2
                    )
                );

                return $student;
            });

        return response()->json([
            'message' => 'Children fetched successfully.',
            'data' => $children,
        ]);
    }

    public function childAttendance(StudentProfile $studentProfile): JsonResponse
    {
        $this->getParentProfileForChild($studentProfile);

        $attendanceQuery = $this->consistentAttendanceFor($studentProfile);
        $attendance = (clone $attendanceQuery)
            ->with(['course', 'batch'])
            ->latest('attendance_date')
            ->paginate(20);

        $summary = [
            'total' => (clone $attendanceQuery)->count(),
            'present' => (clone $attendanceQuery)
                ->where('attendance_status', 'present')->count(),
            'absent' => (clone $attendanceQuery)
                ->where('attendance_status', 'absent')->count(),
            'late' => (clone $attendanceQuery)
                ->where('attendance_status', 'late')->count(),
            'half_day' => (clone $attendanceQuery)
                ->where('attendance_status', 'half_day')->count(),
            'excused' => (clone $attendanceQuery)
                ->where('attendance_status', 'excused')->count(),
            'attendance_percentage' => $this->calculateAttendancePercentage($studentProfile),
        ];

        return response()->json([
            'message' => 'Child attendance fetched successfully.',
            'data' => [
                'student' => $studentProfile->load(['user', 'department', 'batch']),
                'summary' => $summary,
                'records' => $attendance,
            ],
        ]);
    }

    public function childGrades(StudentProfile $studentProfile): JsonResponse
    {
        $this->getParentProfileForChild($studentProfile);

        $gradebookQuery = Gradebook::where('student_profile_id', $studentProfile->id)
            ->institutionallyConsistent()
            ->with('course');
        $this->quizAttemptGradingService
            ->withPendingManualGradingFlag($gradebookQuery);
        $gradebooks = $gradebookQuery->get();

        $hasPendingManualGrading = false;
        $gradebooks->each(function (Gradebook $gradebook) use (&$hasPendingManualGrading) {
            $hasPendingManualGrading =
                $this->quizAttemptGradingService->redactPendingGradebookResults($gradebook) ||
                $hasPendingManualGrading;
        });

        $overallPercentage = $hasPendingManualGrading
            ? null
            : round($gradebooks->avg('percentage') ?? 0, 2);

        return response()->json([
            'message' => 'Child grades fetched successfully.',
            'data' => [
                'student' => $studentProfile->load(['user', 'department', 'batch']),
                'overall_percentage' => $overallPercentage,
                'grading_pending' => $hasPendingManualGrading,
                'gradebooks' => $gradebooks,
            ],
        ]);
    }

    public function childAssignments(StudentProfile $studentProfile): JsonResponse
    {
        $this->getParentProfileForChild($studentProfile);

        $courseIds = CourseEnrollment::where('student_profile_id', $studentProfile->id)
            ->institutionallyConsistent()
            ->pluck('course_id');

        $pendingAssignments = Assignment::whereIn('course_id', $courseIds)
            ->whereDoesntHave('submissions', function ($query) use ($studentProfile) {
                $query->where('student_profile_id', $studentProfile->id);
            })
            ->with('course')
            ->get();

        $submittedAssignments = AssignmentSubmission::where('student_profile_id', $studentProfile->id)
            ->institutionallyConsistent()
            ->with(['assignment.course', 'evaluation'])
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Child assignments fetched successfully.',
            'data' => [
                'student' => $studentProfile->load(['user', 'department', 'batch']),
                'pending_assignments' => $pendingAssignments,
                'submitted_assignments' => $submittedAssignments,
            ],
        ]);
    }

    public function childCourses(StudentProfile $studentProfile): JsonResponse
    {
        $this->getParentProfileForChild($studentProfile);

        $enrolledCourses = CourseEnrollment::where('student_profile_id', $studentProfile->id)
            ->institutionallyConsistent()
            ->with(['course.department', 'course.sections'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Child courses fetched successfully.',
            'data' => [
                'student' => $studentProfile->load(['user', 'department', 'batch']),
                'enrolled_courses' => $enrolledCourses,
            ],
        ]);
    }

    public function childLiveClasses(StudentProfile $studentProfile): JsonResponse
    {
        $this->getParentProfileForChild($studentProfile);

        $upcomingLiveClasses = LiveClass::whereHas('course.enrollments', function ($query) use ($studentProfile) {
            $query->institutionallyConsistent()
                ->where('student_profile_id', $studentProfile->id);
        })
            ->where('institution_id', $studentProfile->institution_id)
            ->where('scheduled_start_time', '>=', now())
            ->with(['course', 'teacherProfile.user'])
            ->orderBy('scheduled_start_time')
            ->get();

        $pastLiveClasses = LiveClass::whereHas('course.enrollments', function ($query) use ($studentProfile) {
            $query->institutionallyConsistent()
                ->where('student_profile_id', $studentProfile->id);
        })
            ->where('institution_id', $studentProfile->institution_id)
            ->where('scheduled_start_time', '<', now())
            ->with(['course', 'teacherProfile.user'])
            ->latest('scheduled_start_time')
            ->paginate(20);

        return response()->json([
            'message' => 'Child live classes fetched successfully.',
            'data' => [
                'student' => $studentProfile->load(['user', 'department', 'batch']),
                'upcoming' => $upcomingLiveClasses,
                'past' => $pastLiveClasses,
            ],
        ]);
    }

    private function calculateAttendancePercentage(StudentProfile $studentProfile): float
    {
        $attendanceQuery = $this->consistentAttendanceFor($studentProfile);

        $total = (clone $attendanceQuery)->count();
        if ($total === 0) {
            return 0;
        }

        $present = (clone $attendanceQuery)
            ->whereIn('attendance_status', ['present', 'late', 'excused'])
            ->count();

        return round(($present / $total) * 100, 2);
    }

    private function consistentAttendanceFor(StudentProfile $studentProfile): Builder
    {
        return AttendanceRecord::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('institution_id', $studentProfile->institution_id)
            ->where(function (Builder $query) use ($studentProfile): void {
                $query
                    ->whereNull('course_id')
                    ->orWhereHas('course', function (Builder $courseQuery) use ($studentProfile): void {
                        $courseQuery->where(
                            'institution_id',
                            $studentProfile->institution_id
                        );
                    });
            })
            ->where(function (Builder $query) use ($studentProfile): void {
                $query
                    ->whereNull('batch_id')
                    ->orWhereHas('batch', function (Builder $batchQuery) use ($studentProfile): void {
                        $batchQuery->where(
                            'institution_id',
                            $studentProfile->institution_id
                        );
                    });
            });
    }

    private function hideInconsistentCertificateGradebook(Certificate $certificate): void
    {
        if (! $certificate->relationLoaded('gradebook')) {
            return;
        }

        $gradebook = $certificate->getRelation('gradebook');

        if (
            $gradebook &&
            (
                (int) $gradebook->student_profile_id !== (int) $certificate->student_profile_id ||
                (int) $gradebook->course_id !== (int) $certificate->course_id
            )
        ) {
            $certificate->setRelation('gradebook', null);
        }
    }
}
