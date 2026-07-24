<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInstitutionSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => [
                'sometimes',
                'required',
                'exists:institutions,id',
            ],

            'subscription_plan_id' => [
                'sometimes',
                'required',
                'exists:subscription_plans,id',
            ],

            'start_date' => [
                'sometimes',
                'required',
                'date',
            ],

            'end_date' => [
                'sometimes',
                'required',
                'date',
                'after:start_date',
            ],

            'amount_paid' => [
                'sometimes',
                'required',
                'numeric',
                'min:0',
            ],

            'payment_status' => [
                'nullable',
                'in:pending,paid,failed,refunded',
            ],

            'status' => [
                'nullable',
                'in:active,expired,cancelled',
            ],

            'payment_reference' => [
                'nullable',
                'string',
                'max:255',
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ];
    }
}
