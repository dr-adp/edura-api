<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuditLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['sometimes', 'integer', 'exists:institutions,id'],
            'action' => ['sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'auditable_type' => ['sometimes', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\\\\.-]+$/'],
            'auditable_id' => ['sometimes', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'search' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
