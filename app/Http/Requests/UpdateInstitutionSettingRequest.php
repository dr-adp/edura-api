<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInstitutionSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'in:branding,localization,academic,communication,certificates,ai,security,storage',
            ],
            'key' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z0-9_.-]+$/'],
            'value' => ['nullable'],
            'value_type' => ['nullable', 'in:string,integer,decimal,boolean,array,json'],
            'is_public' => ['nullable', 'boolean'],
            'is_encrypted' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:active,inactive'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
