<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institution_id' => [
                'required',
                'exists:institutions,id',
            ],

            'department_id' => [
                'nullable',
                'exists:departments,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('batches', 'code')
                    ->where(
                        'institution_id',
                        $this->institution_id
                    ),
            ],

            'start_date' => [
                'nullable',
                'date',
            ],

            'end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],

            'mode' => [
                'nullable',
                'in:offline,online,hybrid',
            ],

            'status' => [
                'nullable',
                'in:active,inactive,completed',
            ],

            'description' => [
                'nullable',
                'string',
            ],
        ];
    }
}
