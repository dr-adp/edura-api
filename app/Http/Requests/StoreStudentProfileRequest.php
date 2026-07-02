<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentProfileRequest extends FormRequest
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
                Rule::unique('student_profiles', 'user_id')
                    ->where(
                        'institution_id',
                        $this->institution_id
                    ),
            ],

            'department_id' => [
                'nullable',
                'exists:departments,id',
            ],

            'batch_id' => [
                'nullable',
                'exists:batches,id',
            ],

            'roll_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'date_of_birth' => [
                'nullable',
                'date',
            ],

            'gender' => [
                'nullable',
                'in:male,female,other',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'parent_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'parent_phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'address' => [
                'nullable',
                'string',
            ],

            'status' => [
                'nullable',
                'in:active,inactive',
            ],
        ];
    }
}
