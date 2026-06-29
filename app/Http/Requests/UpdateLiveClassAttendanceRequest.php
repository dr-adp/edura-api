<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLiveClassAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attendance_status' => [
                'sometimes',
                'in:present,absent,late',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ];
    }
}
