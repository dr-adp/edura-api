<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssignmentSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignment_id' => [
                'required',
                'exists:assignments,id',
            ],

            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
                Rule::unique('assignment_submissions', 'student_profile_id')
                    ->where(
                        'assignment_id',
                        $this->assignment_id
                    ),
            ],

            'submission_text' => [
                'nullable',
                'string',
            ],

            'external_url' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'file' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,zip,rar,txt,mp4,mov,avi,mkv,webm',
                'max:512000',
            ],

            'status' => [
                'nullable',
                'in:draft,submitted,reviewed,returned',
            ],
        ];
    }
}
