<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subscription_plan_id' => ['required', 'exists:subscription_plans,id'],
            'feature_id' => [
                'required',
                'exists:features,id',
                Rule::unique('plan_features', 'feature_id')
                    ->where('subscription_plan_id', $this->subscription_plan_id),
            ],
            'enabled' => ['nullable', 'boolean'],
            'value' => ['nullable'],
            'status' => ['nullable', 'in:active,inactive'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
