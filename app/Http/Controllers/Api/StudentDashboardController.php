<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\InstitutionUser;
use App\Models\LiveClass;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class StudentDashboardController extends Controller
{
    public function show(StudentProfile $studentProfile): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        /*
        |--------------------------------------------------------------------------
        | Student Role
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('student')) {

            $authStudentProfile = StudentProfile::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$authStudentProfile ||
                $authStudentProfile->id !== $studentProfile->id
            ) {
                abort(
                    403,
                    'Unauthorized: You can only view your own dashboard.'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Institution Admin Role
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('institution-admin')) {

            $authInstProfile = InstitutionUser::where(
                'user_id',
                $user->id
            )->first();

            if (
                !$authInstProfile ||
                $studentProfile->institution_id !== $authInstProfile->institution_id
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

            $isMyStudent = $studentProfile
                ->courseEnrollments()
                ->whereHas(
                    'course',
                    function ($query) use ($teacherProfile) {

                        $query->where(
                            'teacher_profile_id',
                            $teacherProfile->id
                        );
                    }
                )
                ->exists();

            if (!$isMyStudent) {

                abort(
                    403,
                    'Unauthorized: This student is not enrolled in your courses.'
                );
            }
        }

        /*
|--------------------------------------------------------------------------
| Parent Role
|--------------------------------------------------------------------------
*/
        if ($user->hasRole('parent')) {

            $parentProfile = $user->parentProfile;

            if (!$parentProfile) {

                abort(
                    403,
                    'Unauthorized: Parent profile not found.'
                );
            }

            if (
                $parentProfile->student_profile_id !==
                $studentProfile->id
            ) {

                abort(
                    403,
                    'Unauthorized: You can only view your own child dashboard.'
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

        $courseIds = $enrolledCourses
            ->pluck('course_id')
            ->unique()
            ->values();

        $completedCourses = $enrolledCourses
            ->where('status', 'completed')
            ->values();

        $pendingAssignments = Assignment::whereIn(
            'course_id',
            $courseIds
        )
            ->whereDoesntHave(
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

        $submittedAssignments = $studentProfile->assignmentSubmissions;

        $upcomingLiveClasses = LiveClass::whereIn(
            'course_id',
            $courseIds
        )
            ->where(
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
