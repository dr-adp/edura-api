<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:subscription_plans,code'],
            'price' => ['required', 'numeric', 'min:0'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'trial_days' => ['nullable', 'integer', 'min:0'],
            'max_teachers' => ['required', 'integer', 'min:1'],
            'max_students' => ['required', 'integer', 'min:1'],
            'max_courses' => ['required', 'integer', 'min:1'],
            'storage_limit_mb' => ['required', 'integer', 'min:100'],
            'included_ai_credits' => ['nullable', 'numeric', 'min:0'],
            'api_request_limit' => ['nullable', 'integer', 'min:1'],
            'limits' => ['nullable', 'array'],
            'allow_live_classes' => ['boolean'],
            'allow_recorded_classes' => ['boolean'],
            'allow_ai_reports' => ['boolean'],
            'allow_hand_sign_module' => ['boolean'],
            'allow_noticeboard' => ['boolean'],
            'allow_notes_upload' => ['boolean'],
            'description' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'status' => ['nullable', 'in:active,inactive'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
