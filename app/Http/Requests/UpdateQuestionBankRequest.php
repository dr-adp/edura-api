<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuestionBankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => ['sometimes', 'exists:courses,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],

            'question_text' => ['sometimes', 'string', 'max:1000'],
            'question_description' => ['nullable', 'string'],

            'question_type' => [
                'nullable',
                'in:mcq,true_false,short_answer,long_answer,fill_blank',
            ],

            'difficulty' => [
                'nullable',
                'in:easy,medium,hard',
            ],

            'marks' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'topic' => [
                'nullable',
                'string',
                'max:255',
            ],

            'explanation' => [
                'nullable',
                'string',
            ],

            'status' => [
                'nullable',
                'in:active,inactive',
            ],
        ];
    }
}