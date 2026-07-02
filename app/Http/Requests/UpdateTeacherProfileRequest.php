<?php

namespace App\Http\Requests;

use App\Models\TeacherProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var TeacherProfile $teacherProfile */
        $teacherProfile = $this->route('teacherProfile');

        return [
            'institution_id' => [
                'sometimes',
                'required',
                'exists:institutions,id',
            ],

            'user_id' => [
                'sometimes',
                'required',
                'exists:users,id',
                Rule::unique('teacher_profiles', 'user_id')
                    ->where(
                        'institution_id',
                        $this->institution_id
                            ?? $teacherProfile->institution_id
                    )
                    ->ignore($teacherProfile->id),
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
