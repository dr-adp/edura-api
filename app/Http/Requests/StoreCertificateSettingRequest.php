<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCertificateSettingRequest extends FormRequest
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
                Rule::unique('certificate_settings', 'institution_id'),
            ],

            'certificate_title' => [
                'nullable',
                'string',
                'max:255',
            ],

            'certificate_subtitle' => [
                'nullable',
                'string',
                'max:255',
            ],

            'authorized_person_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'authorized_person_designation' => [
                'nullable',
                'string',
                'max:255',
            ],

            'secondary_signatory_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'secondary_signatory_designation' => [
                'nullable',
                'string',
                'max:255',
            ],

            'verification_url' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'show_qr_code' => [
                'nullable',
                'boolean',
            ],

            'footer_text' => [
                'nullable',
                'string',
            ],

            'logo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            'institution_seal' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            'certificate_background' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:4096',
            ],

            'signature_image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            'secondary_signature_image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            'status' => [
                'nullable',
                'in:active,inactive',
            ],
        ];
    }
}
