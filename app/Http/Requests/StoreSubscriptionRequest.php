<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['required', 'exists:institutions,id'],
            'subscription_plan_id' => ['required', 'exists:subscription_plans,id'],
            'status' => ['nullable', 'in:trial,active,suspended,expired,cancelled'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly'],
            'starts_at' => ['nullable', 'date'],
            'trial_ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'current_period_starts_at' => ['nullable', 'date'],
            'current_period_ends_at' => ['nullable', 'date', 'after_or_equal:current_period_starts_at'],
            'suspended_at' => ['nullable', 'date'],
            'cancelled_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
