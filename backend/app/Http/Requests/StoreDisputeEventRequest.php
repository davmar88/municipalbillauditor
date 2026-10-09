<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PastTimestampRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreDisputeEventRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:acknowledged,response_received,note'],
            'note' => ['nullable', 'string', 'max:5000', 'required_if:type,note'],
            'occurred_at' => PastTimestampRules::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required_if' => 'Write the note you want to add.',
            'occurred_at.before_or_equal' => "This date can't be in the future.",
        ];
    }
}
