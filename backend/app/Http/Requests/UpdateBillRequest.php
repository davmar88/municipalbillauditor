<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LineItemRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBillRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bill_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'period_start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'period_end' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'total_cents' => ['sometimes', 'nullable', 'integer', 'min:-100000000000', 'max:100000000000'],
            'line_items' => ['required', 'array', 'min:1', 'max:100'],
            ...LineItemRules::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'line_items.required' => 'Enter at least one line item from your bill.',
            'line_items.min' => 'Enter at least one line item from your bill.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $lineItems = $this->input('line_items');
        if (is_string($lineItems)) {
            $decoded = json_decode($lineItems, true);
            if (is_array($decoded)) {
                $this->merge(['line_items' => $decoded]);
            }
        }
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $bill = $this->route('bill');
                $start = $this->has('period_start') ? $this->input('period_start') : $bill?->period_start?->toDateString();
                $end = $this->has('period_end') ? $this->input('period_end') : $bill?->period_end?->toDateString();
                if (is_string($start) && is_string($end) && $end < $start) {
                    $validator->errors()->add('period_end', 'The end of the billing period must be on or after its start.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function billData(): array
    {
        $data = $this->validated();
        if (isset($data['total_cents'])) {
            $data['total_cents'] = (int) $data['total_cents'];
        }

        return $data;
    }
}
