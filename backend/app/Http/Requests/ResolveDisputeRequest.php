<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveDisputeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', 'in:resolved,rejected'],
            'outcome_amount_cents' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
