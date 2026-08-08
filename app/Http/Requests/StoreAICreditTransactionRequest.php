<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAICreditTransactionRequest extends FormRequest
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
            'transaction_type' => [
                'required',
                'in:grant,purchase,consume,refund,adjustment,expiry',
            ],
            'credits' => ['required', 'numeric', 'not_in:0'],
            'source' => ['nullable', 'string', 'max:100'],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
