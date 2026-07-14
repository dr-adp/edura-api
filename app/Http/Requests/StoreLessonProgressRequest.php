<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLessonProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_enrollment_id' => [
                'required',
                'exists:course_enrollments,id',
            ],

            'lesson_id' => [
                'required',
                'exists:lessons,id',
                Rule::unique(
                    'lesson_progress',
                    'lesson_id'
                )->where(
                    'course_enrollment_id',
                    $this->course_enrollment_id
                ),
            ],

            'status' => [
                'nullable',
                'in:not_started,in_progress,completed',
            ],

            'progress_percentage' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            'watch_time_minutes' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ];
    }
}
