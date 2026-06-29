<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLiveClassAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'live_class_id' => [
                'required',
                'exists:live_classes,id',
            ],

            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
                Rule::unique('live_class_attendances')
                    ->where(function ($query) {
                        return $query
                            ->where(
                                'live_class_id',
                                $this->live_class_id
                            )
                            ->where(
                                'student_profile_id',
                                $this->student_profile_id
                            );
                    }),
            ],

            'attendance_status' => [
                'required',
                'in:present,absent,late',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ];
    }
}
