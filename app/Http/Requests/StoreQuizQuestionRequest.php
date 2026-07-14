<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quiz_id' => [
                'required',
                'exists:quizzes,id',
            ],

            'question_bank_id' => [
                'required',
                'exists:question_banks,id',
                Rule::unique(
                    'quiz_questions',
                    'question_bank_id'
                )->where(
                    'quiz_id',
                    $this->quiz_id
                ),
            ],

            'marks' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'sort_order' => [
                'nullable',
                'integer',
            ],
        ];
    }
}
