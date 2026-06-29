<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => ['sometimes', 'exists:courses,id'],
            'course_section_id' => ['nullable', 'exists:course_sections,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],

            'title' => ['sometimes', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],

            'maximum_marks' => ['nullable', 'numeric', 'min:0'],

            'available_from' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],

            'allow_late_submission' => ['boolean'],

            'status' => ['nullable', 'in:draft,published,closed'],
        ];
    }
}
