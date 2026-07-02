<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => [
                'required',
                'exists:institutions,id',
            ],

            'user_id' => [
                'required',
                'exists:users,id',
                Rule::unique('teacher_profiles', 'user_id')
                    ->where(
                        'institution_id',
                        $this->institution_id
                    ),
            ],

            'department_id' => [
                'nullable',
                'exists:departments,id',
            ],

            'employee_code' => [
                'nullable',
                'string',
                'max:100',
            ],

            'qualification' => [
                'nullable',
                'string',
                'max:255',
            ],

            'specialization' => [
                'nullable',
                'string',
                'max:255',
            ],

            'bio' => [
                'nullable',
                'string',
            ],

            'experience_years' => [
                'nullable',
                'string',
                'max:50',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'status' => [
                'nullable',
                'in:active,inactive',
            ],
        ];
    }
}
