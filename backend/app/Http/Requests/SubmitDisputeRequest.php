<?php

namespace App\Http\Requests;

use App\Enums\DisputeChannel;
use App\Http\Requests\Concerns\PastTimestampRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitDisputeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(DisputeChannel::class)],
            'municipality_reference' => ['nullable', 'string', 'max:100'],
            'submitted_at' => PastTimestampRules::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'submitted_at.before_or_equal' => "The date you sent the dispute can't be in the future.",
        ];
    }
}
