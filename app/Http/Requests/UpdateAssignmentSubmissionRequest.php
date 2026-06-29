<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssignmentSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
