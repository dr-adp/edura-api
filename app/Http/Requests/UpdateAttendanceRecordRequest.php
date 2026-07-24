<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendanceRecordRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [

            'institution_id' => [
                'nullable',
                'exists:institutions,id',
            ],

            'batch_id' => [
                'nullable',
                'exists:batches,id',
            ],

            'course_id' => [
                'nullable',
                'exists:courses,id',
            ],

            'student_profile_id' => [
                'sometimes',
                'required',
                'exists:student_profiles,id',
            ],

            'attendance_date' => [
                'sometimes',
                'date',
            ],

            'attendance_status' => [
                'nullable',
                Rule::in([
                    'present',
                    'absent',
                    'late',
                    'half_day',
                    'excused',
                ]),
            ],

            'check_in_at' => [
                'nullable',
                'date',
            ],

            'check_out_at' => [
                'nullable',
                'date',
                'after_or_equal:check_in_at',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],

        ];
    }
}
