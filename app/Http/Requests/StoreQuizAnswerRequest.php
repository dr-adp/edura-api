<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuizAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quiz_attempt_id' => [
                'required',
                'exists:quiz_attempts,id',
            ],

            'question_bank_id' => [
                'required',
                'exists:question_banks,id',
                Rule::unique(
                    'quiz_answers',
                    'question_bank_id'
                )->where(
                    'quiz_attempt_id',
                    $this->quiz_attempt_id
                ),
            ],

            'question_option_id' => [
                'nullable',
                'exists:question_options,id',
            ],

            'answer_text' => [
                'nullable',
                'string',
            ],
        ];
    }
}
