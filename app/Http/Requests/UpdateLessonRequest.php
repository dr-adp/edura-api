<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLessonRequest extends FormRequest
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

            'title' => ['sometimes', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],

            'lesson_type' => ['nullable', 'in:text,video,pdf,mixed'],

            'video_url' => ['nullable', 'string', 'max:500'],
            'pdf_url' => ['nullable', 'string', 'max:500'],
            'external_resource_url' => ['nullable', 'string', 'max:500'],

            'duration_minutes' => ['nullable', 'integer', 'min:1'],

            'is_preview' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],

            'status' => ['nullable', 'in:draft,published,archived'],
        ];
    }
}
