<?php

namespace App\Http\Requests;

use App\Models\QuizQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var QuizQuestion $quizQuestion */
        $quizQuestion = $this->route('quiz_question');

        return [
            'quiz_id' => [
                'sometimes',
                'exists:quizzes,id',
            ],

            'question_bank_id' => [
                'sometimes',
                'exists:question_banks,id',
                Rule::unique(
                    'quiz_questions',
                    'question_bank_id'
                )
                    ->where(
                        'quiz_id',
                        $this->quiz_id
                            ?? $quizQuestion->quiz_id
                    )
                    ->ignore($quizQuestion->id),
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
