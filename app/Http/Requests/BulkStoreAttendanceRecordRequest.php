<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkStoreAttendanceRecordRequest extends FormRequest
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

            'attendance_date' => [
                'nullable',
                'date',
            ],

            'records' => [
                'required',
                'array',
                'min:1',
            ],

            'records.*.student_profile_id' => [
                'required',
                'exists:student_profiles,id',
            ],

            'records.*.attendance_status' => [
                'nullable',
                Rule::in([
                    'present',
                    'absent',
                    'late',
                    'half_day',
                    'excused',
                ]),
            ],

            'records.*.check_in_at' => [
                'nullable',
                'date',
            ],

            'records.*.check_out_at' => [
                'nullable',
                'date',
                'after_or_equal:records.*.check_in_at',
            ],

            'records.*.remarks' => [
                'nullable',
                'string',
            ],

        ];
    }
}
