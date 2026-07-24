<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceReportRequest extends FormRequest
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
                'nullable',
                'exists:student_profiles,id',
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

            'date' => [
                'nullable',
                'date',
            ],

            'from_date' => [
                'nullable',
                'date',
            ],

            'to_date' => [
                'nullable',
                'date',
                'after_or_equal:from_date',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],

        ];
    }
}
