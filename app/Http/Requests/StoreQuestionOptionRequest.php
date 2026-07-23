<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_bank_id' => [
                'required',
                'exists:question_banks,id',
            ],

            'option_text' => [
                'required',
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
