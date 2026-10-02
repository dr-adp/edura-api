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
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ParentDashboardController extends Controller
{
    private function getParentProfileForChild(
        StudentProfile $studentProfile
    ): ParentProfile {
        $parentProfile = ParentProfile::where('user_id', Auth::id())
            ->where('student_profile_id', $studentProfile->id)
            ->where('status', 'active')
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
            ->with('course')
            ->get();

        $overallProgress = round($enrolledCourses->avg('progress_percentage') ?? 0, 2);

        $pendingAssignments = Assignment::whereDoesntHave('submissions', function ($query) use ($studentProfile) {
            $query->where('student_profile_id', $studentProfile->id);
        })
            ->with('course')
            ->get();

        $recentAttendance = AttendanceRecord::where('student_profile_id', $studentProfile->id)
            ->with('course')
            ->latest('attendance_date')
            ->take(10)
            ->get();

        $attendanceSummary = [
            'total' => AttendanceRecord::where('student_profile_id', $studentProfile->id)->count(),
            'present' => AttendanceRecord::where('student_profile_id', $studentProfile->id)
                ->where('attendance_status', 'present')->count(),
            'absent' => AttendanceRecord::where('student_profile_id', $studentProfile->id)
                ->where('attendance_status', 'absent')->count(),
            'late' => AttendanceRecord::where('student_profile_id', $studentProfile->id)
                ->where('attendance_status', 'late')->count(),
            'excused' => AttendanceRecord::where('student_profile_id', $studentProfile->id)
                ->where('attendance_status', 'excused')->count(),
        ];

        $gradebooks = Gradebook::where('student_profile_id', $studentProfile->id)
            ->with('course')
            ->get();

        $upcomingLiveClasses = LiveClass::whereHas('course.enrollments', function ($query) use ($studentProfile) {
            $query->where('student_profile_id', $studentProfile->id);
        })
            ->where('scheduled_start_time', '>=', now())
            ->with(['course', 'teacherProfile.user'])
            ->orderBy('scheduled_start_time')
            ->get();

        $quizAttempts = QuizAttempt::where('student_profile_id', $studentProfile->id)
            ->with('quiz')
            ->latest()
            ->get();

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
            ],
        ]);
    }

    public function children(): JsonResponse
    {
        $studentProfileIds = ParentProfile::where('user_id', Auth::id())
            ->where('status', 'active')
            ->pluck('student_profile_id')
            ->unique()
            ->values();

        $children = StudentProfile::whereIn('id', $studentProfileIds)
            ->with(['user', 'department', 'batch', 'institution'])
            ->get()
            ->map(function ($student) {
                $enrollments = CourseEnrollment::where(
                    'student_profile_id',
                    $student->id
                );

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

        $attendance = AttendanceRecord::where('student_profile_id', $studentProfile->id)
            ->with(['course', 'batch'])
            ->latest('attendance_date')
            ->paginate(20);

        $summary = [
            'total' => AttendanceRecord::where('student_profile_id', $studentProfile->id)->count(),
            'present' => AttendanceRecord::where('student_profile_id', $studentProfile->id)
                ->where('attendance_status', 'present')->count(),
            'absent' => AttendanceRecord::where('student_profile_id', $studentProfile->id)
                ->where('attendance_status', 'absent')->count(),
            'late' => AttendanceRecord::where('student_profile_id', $studentProfile->id)
                ->where('attendance_status', 'late')->count(),
            'half_day' => AttendanceRecord::where('student_profile_id', $studentProfile->id)
                ->where('attendance_status', 'half_day')->count(),
            'excused' => AttendanceRecord::where('student_profile_id', $studentProfile->id)
                ->where('attendance_status', 'excused')->count(),
            'attendance_percentage' => $this->calculateAttendancePercentage($studentProfile->id),
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

        $gradebooks = Gradebook::where('student_profile_id', $studentProfile->id)
            ->with('course')
            ->get();

        $overallPercentage = round($gradebooks->avg('percentage') ?? 0, 2);

        return response()->json([
            'message' => 'Child grades fetched successfully.',
            'data' => [
                'student' => $studentProfile->load(['user', 'department', 'batch']),
                'overall_percentage' => $overallPercentage,
                'gradebooks' => $gradebooks,
            ],
        ]);
    }

    public function childAssignments(StudentProfile $studentProfile): JsonResponse
    {
        $this->getParentProfileForChild($studentProfile);

        $pendingAssignments = Assignment::whereDoesntHave('submissions', function ($query) use ($studentProfile) {
            $query->where('student_profile_id', $studentProfile->id);
        })
            ->with('course')
            ->get();

        $submittedAssignments = AssignmentSubmission::where('student_profile_id', $studentProfile->id)
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
            $query->where('student_profile_id', $studentProfile->id);
        })
            ->where('scheduled_start_time', '>=', now())
            ->with(['course', 'teacherProfile.user'])
            ->orderBy('scheduled_start_time')
            ->get();

        $pastLiveClasses = LiveClass::whereHas('course.enrollments', function ($query) use ($studentProfile) {
            $query->where('student_profile_id', $studentProfile->id);
        })
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

    private function calculateAttendancePercentage(int $studentProfileId): float
    {
        $total = AttendanceRecord::where('student_profile_id', $studentProfileId)->count();
        if ($total === 0) {
            return 0;
        }

        $present = AttendanceRecord::where('student_profile_id', $studentProfileId)
            ->whereIn('attendance_status', ['present', 'late', 'excused'])
            ->count();

        return round(($present / $total) * 100, 2);
    }
}
