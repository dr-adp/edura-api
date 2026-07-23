<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuizAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_option_id' => [
                'nullable',
                'exists:question_options,id',
            ],

            'answer_text' => [
                'nullable',
                'string',
            ],

            'marks_obtained' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'is_correct' => [
                'boolean',
            ],
        ];
    }
}
