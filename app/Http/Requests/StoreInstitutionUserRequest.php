<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInstitutionUserRequest extends FormRequest
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
                Rule::unique('institution_users', 'user_id')
                    ->where(
                        'institution_id',
                        $this->institution_id
                    ),
            ],

            'role_in_institution' => [
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
