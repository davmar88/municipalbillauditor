<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDisputeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'letter_subject' => ['sometimes', 'required', 'string', 'max:255'],
            'letter_body' => ['sometimes', 'required', 'string', 'max:20000'],
        ];
    }
}
