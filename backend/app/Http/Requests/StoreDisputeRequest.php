<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDisputeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'finding_ids' => ['required', 'array', 'min:1', 'max:50'],
            'finding_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'finding_ids.required' => 'Choose at least one finding to dispute.',
            'finding_ids.min' => 'Choose at least one finding to dispute.',
        ];
    }
}
