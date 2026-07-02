<?php

namespace App\Http\Requests;

use App\Models\InstitutionUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInstitutionUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var InstitutionUser $institutionUser */
        $institutionUser = $this->route('institutionUser');

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
                Rule::unique('institution_users', 'user_id')
                    ->where(
                        'institution_id',
                        $this->institution_id
                            ?? $institutionUser->institution_id
                    )
                    ->ignore($institutionUser->id),
            ],

            'role_in_institution' => [
                'sometimes',
                'required',
                'in:owner,admin,teacher,student,parent',
            ],

            'status' => [
                'nullable',
                'in:active,inactive',
            ],
        ];
    }
}
