<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUsageStatisticRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subscription_id' => ['nullable', 'exists:subscriptions,id'],
            'metric' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'in:students,teachers,courses,storage_mb,api_requests,ai_credits',
            ],
            'period_start' => ['sometimes', 'required', 'date'],
            'period_end' => ['sometimes', 'required', 'date', 'after_or_equal:period_start'],
            'used_value' => ['sometimes', 'required', 'numeric', 'min:0'],
            'limit_value' => ['nullable', 'numeric', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
