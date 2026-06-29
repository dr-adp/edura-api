<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],
            'teacher_profile_id' => ['nullable', 'exists:teacher_profiles,id'],

            'title' => ['sometimes', 'required', 'string', 'max:255'],

            'short_description' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],

            'price' => ['nullable', 'numeric', 'min:0'],

            'course_type' => ['nullable', 'in:free,paid,private'],
            'level' => ['nullable', 'in:beginner,intermediate,advanced'],

            'language' => ['nullable', 'string', 'max:100'],
            'duration_hours' => ['nullable', 'integer', 'min:1'],

            'certificate_enabled' => ['boolean'],
            'live_class_enabled' => ['boolean'],

            'status' => ['nullable', 'in:draft,published,archived'],
        ];
    }
}
