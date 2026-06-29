<?php

namespace App\Http\Requests;

use App\Models\CourseEnrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var CourseEnrollment $courseEnrollment */
        $courseEnrollment = $this->route('courseEnrollment');

        return [
            'course_id' => [
                'sometimes',
                'exists:courses,id',
            ],

            'student_profile_id' => [
                'sometimes',
                'exists:student_profiles,id',
                Rule::unique('course_enrollments', 'student_profile_id')
                    ->where(
                        'course_id',
                        $this->course_id ?? $courseEnrollment->course_id
                    )
                    ->ignore($courseEnrollment->id),
            ],

            'enrollment_date' => [
                'nullable',
                'date',
            ],

            'payment_status' => [
                'nullable',
                'in:free,pending,paid,failed,refunded',
            ],

            'amount_paid' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'progress_percentage' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            'status' => [
                'nullable',
                'in:active,completed,cancelled,expired',
            ],

            'completed_at' => [
                'nullable',
                'date',
            ],
        ];
    }
}
