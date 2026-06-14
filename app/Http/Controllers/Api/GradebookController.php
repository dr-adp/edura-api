<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\Gradebook;
use App\Models\QuizAttempt;
use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\AssignmentEvaluation;
use App\Http\Controllers\Controller;
use Illuminate\Validation\ValidationException;

class GradebookController extends Controller
{
    public function index(): JsonResponse
    {
        $gradebooks = Gradebook::with([
            'course',
            'studentProfile.user',
            'studentProfile.batch'
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Gradebook records fetched successfully.',
            'data' => $gradebooks,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'student_profile_id' => ['required', 'exists:student_profiles,id'],
        ]);

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

        /*
    |--------------------------------------------------------------------------
    | Data Consistency Check
    |--------------------------------------------------------------------------
    */
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
}
