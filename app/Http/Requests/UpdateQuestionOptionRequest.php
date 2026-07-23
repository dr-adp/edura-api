<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuestionOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_bank_id' => [
                'sometimes',
                'exists:question_banks,id',
            ],

            'option_text' => [
                'sometimes',
                'string',
                'max:1000',
            ],

            'is_correct' => [
                'boolean',
            ],

            'sort_order' => [
                'nullable',
                'integer',
            ],
        ];
    }
}
