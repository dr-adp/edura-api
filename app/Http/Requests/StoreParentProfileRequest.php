<?php

namespace App\Http\Requests;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreParentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasAnyRole(['super-admin', 'institution-admin']);
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['required', 'exists:institutions,id'],

            'user_id' => [
                'required',
                'exists:users,id',
            ],

            'student_profile_id' => [
                'required',
                'exists:student_profiles,id',
            ],

            'relationship' => ['nullable', 'string', 'max:100'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $userId = $this->input('user_id');
            if (is_numeric($userId)) {
                $parentUser = User::find($userId);

                if ($parentUser && ! $parentUser->hasRole('parent')) {
                    $validator->errors()->add('user_id', 'The selected user must have the parent role.');
                }
            }

            $institutionId = $this->input('institution_id');
            $studentProfileId = $this->input('student_profile_id');

            if (is_numeric($institutionId) && is_numeric($studentProfileId)) {
                $studentProfile = StudentProfile::find($studentProfileId);

                if (
                    $studentProfile &&
                    (int) $studentProfile->institution_id !== (int) $institutionId
                ) {
                    $validator->errors()->add(
                        'student_profile_id',
                        'The selected student must belong to the selected institution.'
                    );
                }
            }
        });
    }
}
