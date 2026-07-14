<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuizAttemptRequest extends FormRequest
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

            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
            ],
        ];
    }
}
