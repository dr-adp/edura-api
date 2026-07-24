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
            'max_teachers' => ['required', 'integer', 'min:1'],
            'max_students' => ['required', 'integer', 'min:1'],
            'max_courses' => ['required', 'integer', 'min:1'],
            'storage_limit_mb' => ['required', 'integer', 'min:100'],
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
