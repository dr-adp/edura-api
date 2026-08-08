<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInstitutionSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['required', 'exists:institutions,id'],
            'group' => [
                'required',
                'string',
                'max:100',
                'in:branding,localization,academic,communication,certificates,ai,security,storage',
            ],
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_.-]+$/'],
            'value' => ['nullable'],
            'value_type' => ['nullable', 'in:string,integer,decimal,boolean,array,json'],
            'is_public' => ['nullable', 'boolean'],
            'is_encrypted' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:active,inactive'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
