<?php

namespace App\Http\Requests;

use App\Models\LessonProgress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLessonProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var LessonProgress $lessonProgress */
        $lessonProgress = $this->route('lesson_progress');

        return [
            'course_enrollment_id' => [
                'sometimes',
                'exists:course_enrollments,id',
            ],

            'lesson_id' => [
                'sometimes',
                'exists:lessons,id',
                Rule::unique(
                    'lesson_progress',
                    'lesson_id'
                )
                    ->where(
                        'course_enrollment_id',
                        $this->course_enrollment_id
                            ?? $lessonProgress->course_enrollment_id
                    )
                    ->ignore($lessonProgress->id),
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
