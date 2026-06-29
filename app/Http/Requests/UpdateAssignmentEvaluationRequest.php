<?php

namespace App\Http\Requests;

use App\Models\AssignmentEvaluation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssignmentEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var AssignmentEvaluation $assignmentEvaluation */
        $assignmentEvaluation = $this->route('assignmentEvaluation');

        return [
            'assignment_submission_id' => [
                'sometimes',
                'exists:assignment_submissions,id',
                Rule::unique(
                    'assignment_evaluations',
                    'assignment_submission_id'
                )->ignore($assignmentEvaluation->id),
            ],

            'teacher_profile_id' => [
                'nullable',
                'exists:teacher_profiles,id',
            ],

            'marks_obtained' => [
                'sometimes',
                'numeric',
                'min:0',
            ],

            'maximum_marks' => [
                'nullable',
                'numeric',
                'min:1',
            ],

            'feedback' => [
                'nullable',
                'string',
            ],

            'result_status' => [
                'nullable',
                'in:passed,failed,needs_improvement',
            ],
        ];
    }
}
