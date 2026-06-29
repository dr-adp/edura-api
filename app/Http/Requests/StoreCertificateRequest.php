<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => [
                'required',
                'exists:courses,id',
            ],

            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ];
    }
}
