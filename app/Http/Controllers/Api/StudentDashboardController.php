<?php

namespace App\Http\Controllers\Api;

use App\Models\StudentProfile;
use App\Models\Assignment;
use App\Models\LiveClass;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class StudentDashboardController extends Controller
{
    public function show(StudentProfile $studentProfile): JsonResponse
    {
        $studentProfile->load([
            'user',
            'institution',
            'department',
            'batch',
            'courseEnrollments.course',
            'certificates',
            'assignmentSubmissions.assignment',
            'quizAttempts.quiz'
        ]);

        $enrolledCourses = $studentProfile->courseEnrollments;

        $completedCourses = $enrolledCourses
            ->where('status', 'completed')
            ->values();

        $pendingAssignments = Assignment::whereDoesntHave(
            'submissions',
            function ($query) use ($studentProfile) {
                $query->where(
                    'student_profile_id',
                    $studentProfile->id
                );
            }
        )
            ->with('course')
            ->get();

        $submittedAssignments =
            $studentProfile->assignmentSubmissions;

        $upcomingLiveClasses = LiveClass::where(
            'scheduled_start_time',
            '>=',
            now()
        )
            ->with([
                'course',
                'teacherProfile'
            ])
            ->orderBy('scheduled_start_time')
            ->get();

        $quizAttempts = $studentProfile->quizAttempts;

        $certificates = $studentProfile->certificates;

        $overallProgress = round(
            $enrolledCourses->avg('progress_percentage') ?? 0,
            2
        );

        return response()->json([
            'message' => 'Student dashboard fetched successfully.',

            'data' => [

                'student' => $studentProfile,

                'enrolled_courses' => $enrolledCourses,

                'completed_courses' => $completedCourses,

                'pending_assignments' => $pendingAssignments,

                'submitted_assignments' => $submittedAssignments,

                'upcoming_live_classes' => $upcomingLiveClasses,

                'quiz_attempts' => $quizAttempts,

                'certificates' => $certificates,

                'overall_progress' => $overallProgress,
            ],
        ]);
    }
}
