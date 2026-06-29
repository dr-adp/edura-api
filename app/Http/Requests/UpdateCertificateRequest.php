<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'nullable',
                'in:pending,issued,revoked',
            ],

            'verification_status' => [
                'nullable',
                'in:valid,revoked,expired',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ];
    }
}
