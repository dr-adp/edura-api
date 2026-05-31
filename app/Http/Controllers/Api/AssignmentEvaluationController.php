<?php

namespace App\Http\Controllers\Api;

use App\Models\AssignmentEvaluation;
use App\Models\AssignmentSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

class AssignmentEvaluationController extends Controller
{
    public function index(): JsonResponse
    {
        $evaluations = AssignmentEvaluation::with([
            'assignmentSubmission.assignment.course',
            'assignmentSubmission.studentProfile.user',
            'teacherProfile.user'
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Assignment evaluations fetched successfully.',
            'data' => $evaluations,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
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

        $validated['maximum_marks'] = $validated['maximum_marks'] ?? 100;
        $validated['evaluated_at'] = now();

        if (!isset($validated['result_status'])) {
            $percentage = ($validated['marks_obtained'] / $validated['maximum_marks']) * 100;

            if ($percentage >= 50) {
                $validated['result_status'] = 'passed';
            } elseif ($percentage >= 35) {
                $validated['result_status'] = 'needs_improvement';
            } else {
                $validated['result_status'] = 'failed';
            }
        }

        $evaluation = AssignmentEvaluation::create($validated);

        $submission = AssignmentSubmission::find($validated['assignment_submission_id']);
        $submission?->update([
            'status' => 'reviewed',
        ]);

        return response()->json([
            'message' => 'Assignment evaluated successfully.',
            'data' => $evaluation->load([
                'assignmentSubmission.assignment.course',
                'assignmentSubmission.studentProfile.user',
                'teacherProfile.user'
            ]),
        ], 201);
    }

    public function show(AssignmentEvaluation $assignmentEvaluation): JsonResponse
    {
        return response()->json([
            'message' => 'Assignment evaluation fetched successfully.',
            'data' => $assignmentEvaluation->load([
                'assignmentSubmission.assignment.course',
                'assignmentSubmission.studentProfile.user',
                'teacherProfile.user'
            ]),
        ]);
    }

    public function update(Request $request, AssignmentEvaluation $assignmentEvaluation): JsonResponse
    {
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

        $validated['evaluated_at'] = now();

        if (!isset($validated['result_status']) && isset($validated['marks_obtained'])) {
            $maximumMarks = $validated['maximum_marks'] ?? $assignmentEvaluation->maximum_marks;
            $percentage = ($validated['marks_obtained'] / $maximumMarks) * 100;

            if ($percentage >= 50) {
                $validated['result_status'] = 'passed';
            } elseif ($percentage >= 35) {
                $validated['result_status'] = 'needs_improvement';
            } else {
                $validated['result_status'] = 'failed';
            }
        }

        $assignmentEvaluation->update($validated);

        return response()->json([
            'message' => 'Assignment evaluation updated successfully.',
            'data' => $assignmentEvaluation->fresh()->load([
                'assignmentSubmission.assignment.course',
                'assignmentSubmission.studentProfile.user',
                'teacherProfile.user'
            ]),
        ]);
    }

    public function destroy(AssignmentEvaluation $assignmentEvaluation): JsonResponse
    {
        $submission = $assignmentEvaluation->assignmentSubmission;

        $assignmentEvaluation->delete();

        $submission?->update([
            'status' => 'submitted',
        ]);

        return response()->json([
            'message' => 'Assignment evaluation deleted successfully.',
        ]);
    }
}
