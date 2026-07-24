<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],

            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('subscription_plans', 'code')
                    ->ignore($this->route('subscription_plan')),
            ],

            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'billing_cycle' => ['sometimes', 'required', 'in:monthly,yearly'],
            'max_teachers' => ['sometimes', 'required', 'integer', 'min:1'],
            'max_students' => ['sometimes', 'required', 'integer', 'min:1'],
            'max_courses' => ['sometimes', 'required', 'integer', 'min:1'],
            'storage_limit_mb' => ['sometimes', 'required', 'integer', 'min:100'],
            'allow_live_classes' => ['boolean'],
            'allow_recorded_classes' => ['boolean'],
            'allow_ai_reports' => ['boolean'],
            'allow_hand_sign_module' => ['boolean'],
            'allow_noticeboard' => ['boolean'],
            'allow_notes_upload' => ['boolean'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
