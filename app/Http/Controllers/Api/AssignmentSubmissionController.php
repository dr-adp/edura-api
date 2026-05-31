<?php

namespace App\Http\Controllers\Api;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class AssignmentSubmissionController extends Controller
{
    public function index(): JsonResponse
    {
        $submissions = AssignmentSubmission::with([
            'assignment.course',
            'studentProfile.user',
            'studentProfile.batch'
        ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Assignment submissions fetched successfully.',
            'data' => $submissions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assignment_id' => ['required', 'exists:assignments,id'],
            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
                Rule::unique('assignment_submissions', 'student_profile_id')
                    ->where('assignment_id', $request->assignment_id),
            ],
            'submission_text' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'max:1000'],
            'file' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,zip,rar,txt,mp4,mov,avi,mkv,webm',
                'max:512000'
            ],
            'status' => ['nullable', 'in:draft,submitted,reviewed,returned'],
        ]);

        if ($request->hasFile('file')) {
            $validated['file_path'] = $request->file('file')->store('assignment-submissions', 'public');
        }

        unset($validated['file']);

        if (($validated['status'] ?? null) === 'submitted') {
            $validated['submitted_at'] = now();
            $validated['is_late'] = $this->checkIfLate($validated['assignment_id']);
        }

        $submission = AssignmentSubmission::create($validated);

        return response()->json([
            'message' => 'Assignment submission created successfully.',
            'data' => $submission->load([
                'assignment.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ], 201);
    }

    public function show(AssignmentSubmission $assignmentSubmission): JsonResponse
    {
        return response()->json([
            'message' => 'Assignment submission fetched successfully.',
            'data' => $assignmentSubmission->load([
                'assignment.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function update(Request $request, AssignmentSubmission $assignmentSubmission): JsonResponse
    {
        $validated = $request->validate([
            'assignment_id' => ['sometimes', 'exists:assignments,id'],
            'student_profile_id' => [
                'sometimes',
                'exists:student_profiles,id',
                Rule::unique('assignment_submissions', 'student_profile_id')
                    ->where('assignment_id', $request->assignment_id ?? $assignmentSubmission->assignment_id)
                    ->ignore($assignmentSubmission->id),
            ],
            'submission_text' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'max:1000'],
            'file' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,zip,rar,txt,mp4,mov,avi,mkv,webm',
                'max:512000'
            ],
            'status' => ['nullable', 'in:draft,submitted,reviewed,returned'],
        ]);

        if ($request->hasFile('file')) {
            if ($assignmentSubmission->file_path && Storage::disk('public')->exists($assignmentSubmission->file_path)) {
                Storage::disk('public')->delete($assignmentSubmission->file_path);
            }

            $validated['file_path'] = $request->file('file')->store('assignment-submissions', 'public');
        }

        unset($validated['file']);

        if (($validated['status'] ?? null) === 'submitted') {
            $validated['submitted_at'] = now();
            $validated['is_late'] = $this->checkIfLate($validated['assignment_id'] ?? $assignmentSubmission->assignment_id);
        }

        $assignmentSubmission->update($validated);

        return response()->json([
            'message' => 'Assignment submission updated successfully.',
            'data' => $assignmentSubmission->fresh()->load([
                'assignment.course',
                'studentProfile.user',
                'studentProfile.batch'
            ]),
        ]);
    }

    public function destroy(AssignmentSubmission $assignmentSubmission): JsonResponse
    {
        if ($assignmentSubmission->file_path && Storage::disk('public')->exists($assignmentSubmission->file_path)) {
            Storage::disk('public')->delete($assignmentSubmission->file_path);
        }

        $assignmentSubmission->delete();

        return response()->json([
            'message' => 'Assignment submission deleted successfully.',
        ]);
    }

    private function checkIfLate(int $assignmentId): bool
    {
        $assignment = Assignment::find($assignmentId);

        if (!$assignment || !$assignment->due_date) {
            return false;
        }

        return now()->greaterThan($assignment->due_date);
    }
}
