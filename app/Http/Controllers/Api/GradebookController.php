<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\Gradebook;
use App\Models\QuizAttempt;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\ParentProfile;
use App\Models\InstitutionUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\AssignmentEvaluation;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GradebookController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $gradebooks = Gradebook::with([
            'course',
            'studentProfile.user',
            'studentProfile.batch'
        ]);

        // SCOPING: Filter by user's role
        if ($user->hasRole('super-admin')) {
            // Super-admin sees all
        } elseif ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where('user_id', $user->id)->first();
            if ($institutionUser) {
                $gradebooks->whereHas('course', function ($q) use ($institutionUser) {
                    $q->where('institution_id', $institutionUser->institution_id);
                });
            }
        } elseif ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            if ($teacherProfile) {
                $gradebooks->whereHas('course', function ($q) use ($teacherProfile) {
                    $q->where('teacher_profile_id', $teacherProfile->id);
                });
            } else {
                $gradebooks->whereRaw('1 = 0');
            }
        } elseif ($user->hasRole('student')) {
            $studentProfile = StudentProfile::where('user_id', $user->id)->first();
            if ($studentProfile) {
                $gradebooks->where('student_profile_id', $studentProfile->id);
            } else {
                $gradebooks->whereRaw('1 = 0');
            }
        } elseif ($user->hasRole('parent')) {
            $parentProfile = ParentProfile::where('user_id', $user->id)->first();
            if ($parentProfile && $parentProfile->student_profile_id) {
                $gradebooks->where('student_profile_id', $parentProfile->student_profile_id);
            } else {
                $gradebooks->whereRaw('1 = 0');
            }
        }

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

        // AUTHORIZATION: Verify teacher owns this course
        $this->authorizeCourseAccess((int) $validated['course_id']);

        $gradebook = $this->calculateGradebook(
            (int) $validated['course_id'],
            (int) $validated['student_profile_id']
        );

        return response()->json([
            'message' => 'Gradebook calculated successfully.',
            'data' => $gradebook->load([
                'course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ], 201);
    }

    public function show(Gradebook $gradebook): JsonResponse
    {
        // AUTHORIZATION
        $this->authorizeGradebookAccess($gradebook);

        return response()->json([
            'message' => 'Gradebook record fetched successfully.',
            'data' => $gradebook->load([
                'course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function update(Request $request, Gradebook $gradebook): JsonResponse
    {
        // AUTHORIZATION: Only teachers who own the course can update
        $this->authorizeCourseAccess((int) $gradebook->course_id);

        $validated = $request->validate([
            'assignment_marks' => ['nullable', 'numeric', 'min:0'],
            'quiz_marks' => ['nullable', 'numeric', 'min:0'],
            'maximum_marks' => ['nullable', 'numeric', 'min:0'],
        ]);

        $assignmentMarks = $validated['assignment_marks'] ?? $gradebook->assignment_marks;
        $quizMarks = $validated['quiz_marks'] ?? $gradebook->quiz_marks;
        $maximumMarks = $validated['maximum_marks'] ?? $gradebook->maximum_marks;

        $totalMarks = $assignmentMarks + $quizMarks;
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
            'data' => $gradebook->fresh()->load([
                'course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function destroy(Gradebook $gradebook): JsonResponse
    {
        // AUTHORIZATION: Only teachers who own the course can delete
        $this->authorizeCourseAccess((int) $gradebook->course_id);

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

        // AUTHORIZATION: Verify teacher owns this course
        $this->authorizeCourseAccess((int) $validated['course_id']);

        $gradebook = $this->calculateGradebook(
            (int) $validated['course_id'],
            (int) $validated['student_profile_id']
        );

        return response()->json([
            'message' => 'Gradebook recalculated successfully.',
            'data' => $gradebook->load([
                'course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    private function calculateGradebook(int $courseId, int $studentProfileId): Gradebook
    {
        $course = Course::findOrFail($courseId);
        $student = StudentProfile::findOrFail($studentProfileId);

        if ((int) $course->institution_id !== (int) $student->institution_id) {
            throw ValidationException::withMessages([
                'student_profile_id' =>
                'Student and Course must belong to the same institution.',
            ]);
        }

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
     * Authorize that the authenticated user has access to this course.
     */
    private function authorizeCourseAccess(int $courseId): void
    {
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        $course = Course::find($courseId);
        if (!$course) {
            abort(404, 'Course not found.');
        }

        if ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where('user_id', $user->id)->first();
            if ($institutionUser && (int) $course->institution_id === (int) $institutionUser->institution_id) {
                return;
            }
            abort(403, 'Unauthorized: Course does not belong to your institution.');
        }

        if ($user->hasRole('teacher')) {
            $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
            if ($teacherProfile && (int) $course->teacher_profile_id === (int) $teacherProfile->id) {
                return;
            }
            abort(403, 'Unauthorized: This course is not assigned to you.');
        }

        abort(403, 'Unauthorized: You do not have permission to manage this gradebook.');
    }

    /**
     * Authorize access to view a gradebook record.
     */
    private function authorizeGradebookAccess(Gradebook $gradebook): void
    {
        $user = Auth::user();

        if ($user->hasRole(['super-admin', 'institution-admin'])) {
            return;
        }

        if ($user->hasRole('teacher')) {
            $this->authorizeCourseAccess((int) $gradebook->course_id);
            return;
        }

        if ($user->hasRole('student')) {
            $studentProfile = StudentProfile::where('user_id', $user->id)->first();
            if ($studentProfile && (int) $studentProfile->id === (int) $gradebook->student_profile_id) {
                return;
            }
            abort(403, 'Unauthorized: You can only view your own gradebook.');
        }

        if ($user->hasRole('parent')) {
            $parentProfile = ParentProfile::where('user_id', $user->id)->first();
            if ($parentProfile && (int) $parentProfile->student_profile_id === (int) $gradebook->student_profile_id) {
                return;
            }
            abort(403, 'Unauthorized: You can only view your child\'s gradebook.');
        }

        abort(403, 'Unauthorized.');
    }
}