<?php

namespace App\Http\Requests;

use App\Models\Batch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Batch $batch */
        $batch = $this->route('batch');

        return [
            'institution_id' => [
                'sometimes',
                'required',
                'exists:institutions,id',
            ],

            'department_id' => [
                'nullable',
                'exists:departments,id',
            ],

            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('batches', 'code')
                    ->where(
                        'institution_id',
                        $this->institution_id
                            ?? $batch->institution_id
                    )
                    ->ignore($batch->id),
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
