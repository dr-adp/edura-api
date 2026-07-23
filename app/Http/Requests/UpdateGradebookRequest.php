<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGradebookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignment_marks' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'quiz_marks' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'maximum_marks' => [
                'nullable',
                'numeric',
                'min:0',
            ],
        ];
    }
}
