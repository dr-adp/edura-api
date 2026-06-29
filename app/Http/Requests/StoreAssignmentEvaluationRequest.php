<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssignmentEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignment_submission_id' => [
                'required',
                'exists:assignment_submissions,id',
                Rule::unique(
                    'assignment_evaluations',
                    'assignment_submission_id'
                ),
            ],

            'teacher_profile_id' => [
                'nullable',
                'exists:teacher_profiles,id',
            ],

            'marks_obtained' => [
                'required',
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
