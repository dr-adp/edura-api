<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUsageStatisticRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['required', 'exists:institutions,id'],
            'subscription_id' => ['nullable', 'exists:subscriptions,id'],
            'metric' => [
                'required',
                'string',
                'max:100',
                'in:students,teachers,courses,storage_mb,api_requests,ai_credits',
            ],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'used_value' => ['nullable', 'numeric', 'min:0'],
            'limit_value' => ['nullable', 'numeric', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
