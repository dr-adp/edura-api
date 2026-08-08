<?php

namespace App\Http\Requests;

use App\Models\PlanFeature;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var PlanFeature $planFeature */
        $planFeature = $this->route('plan_feature');

        return [
            'subscription_plan_id' => [
                'sometimes',
                'required',
                'exists:subscription_plans,id',
            ],
            'feature_id' => [
                'sometimes',
                'required',
                'exists:features,id',
            ],
            'enabled' => ['nullable', 'boolean'],
            'value' => ['nullable'],
            'status' => ['nullable', 'in:active,inactive'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
