<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\TeacherProfile;
use App\Models\CourseEnrollment;
use App\Models\Assignment;
use App\Models\LiveClass;
use App\Models\Quiz;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class TeacherDashboardController extends Controller
{
    private function getTeacherProfile(): TeacherProfile
    {
        $profile = TeacherProfile::where('user_id', Auth::id())->first();

        abort_unless($profile, 404, 'Teacher profile not found.');

        return $profile;
    }

    public function show(): JsonResponse
    {
        $teacherProfile = $this->getTeacherProfile();

        $teacherProfile->load([
            'user',
            'institution',
            'department',
            'courses' => function ($query) {
                $query->withCount([
                    'enrollments as total_students',
                    'lessons as total_lessons',
                    'assignments as total_assignments',
                    'quizzes as total_quizzes',
                ]);
            },
        ]);

        $totalCourses = $teacherProfile->courses->count();
        $totalStudents = $teacherProfile->courses->sum('total_students');

        $upcomingLiveClasses = LiveClass::where('teacher_profile_id', $teacherProfile->id)
            ->where('scheduled_start_time', '>=', now())
            ->with('course')
            ->orderBy('scheduled_start_time')
            ->get();

        $pendingEvaluations = Assignment::where('teacher_profile_id', $teacherProfile->id)
            ->whereHas('submissions', function ($query) {
                $query->where('status', 'submitted');
            })
            ->withCount(['submissions as pending_count' => function ($query) {
                $query->where('status', 'submitted');
            }])
            ->get();

        $recentEnrollments = CourseEnrollment::whereIn('course_id', $teacherProfile->courses->pluck('id'))
            ->with(['studentProfile.user', 'course'])
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'message' => 'Teacher dashboard fetched successfully.',
            'data' => [
                'teacher' => $teacherProfile,
                'total_courses' => $totalCourses,
                'total_students' => $totalStudents,
                'courses' => $teacherProfile->courses,
                'upcoming_live_classes' => $upcomingLiveClasses,
                'pending_evaluations' => $pendingEvaluations,
                'recent_enrollments' => $recentEnrollments,
            ],
        ]);
    }

    public function myCourses(): JsonResponse
    {
        $teacherProfile = $this->getTeacherProfile();

        $courses = Course::where('teacher_profile_id', $teacherProfile->id)
            ->withCount([
                'enrollments as total_students',
                'enrollments as completed_students' => function ($query) {
                    $query->where('status', 'completed');
                },
                'lessons as total_lessons',
                'assignments as total_assignments',
                'quizzes as total_quizzes',
            ])
            ->with(['department', 'batch', 'sections'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'My courses fetched successfully.',
            'data' => $courses,
        ]);
    }

    public function courseStudents(Course $course): JsonResponse
    {
        $teacherProfile = $this->getTeacherProfile();

        if ($course->teacher_profile_id !== $teacherProfile->id) {
            abort(403, 'Unauthorized: This course is not assigned to you.');
        }

        $enrollments = CourseEnrollment::where('course_id', $course->id)
            ->with([
                'studentProfile.user',
                'studentProfile.batch',
                'studentProfile.department',
            ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Course students fetched successfully.',
            'data' => $enrollments,
        ]);
    }

    public function courseStats(Course $course): JsonResponse
    {
        $teacherProfile = $this->getTeacherProfile();

        if ($course->teacher_profile_id !== $teacherProfile->id) {
            abort(403, 'Unauthorized: This course is not assigned to you.');
        }

        $totalEnrollments = CourseEnrollment::where('course_id', $course->id)->count();
        $completedEnrollments = CourseEnrollment::where('course_id', $course->id)
            ->where('status', 'completed')
            ->count();
        $activeEnrollments = CourseEnrollment::where('course_id', $course->id)
            ->where('status', 'active')
            ->count();

        $averageProgress = CourseEnrollment::where('course_id', $course->id)
            ->avg('progress_percentage');

        $totalAssignments = Assignment::where('course_id', $course->id)->count();
        $totalQuizzes = Quiz::where('course_id', $course->id)->count();
        $totalLiveClasses = LiveClass::where('course_id', $course->id)->count();
        $totalLessons = $course->lessons()->count();

        return response()->json([
            'message' => 'Course stats fetched successfully.',
            'data' => [
                'course' => $course->load(['department', 'batch']),
                'enrollments' => [
                    'total' => $totalEnrollments,
                    'active' => $activeEnrollments,
                    'completed' => $completedEnrollments,
                    'completion_rate' => $totalEnrollments > 0
                        ? round(($completedEnrollments / $totalEnrollments) * 100, 2)
                        : 0,
                ],
                'average_progress' => round($averageProgress ?? 0, 2),
                'content' => [
                    'total_lessons' => $totalLessons,
                    'total_assignments' => $totalAssignments,
                    'total_quizzes' => $totalQuizzes,
                    'total_live_classes' => $totalLiveClasses,
                ],
            ],
        ]);
    }
}