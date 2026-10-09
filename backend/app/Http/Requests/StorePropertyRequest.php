<?php

namespace App\Http\Requests;

use App\Enums\PropertyType;
use App\Support\Metros;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePropertyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nickname' => ['required', 'string', 'max:100'],
            'metro' => ['required', 'string', Rule::in(Metros::codes())],
            'account_number' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-\/.]*$/'],
            'address' => ['required', 'string', 'max:255'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_number.regex' => 'Enter the account number as it appears on your bill (letters, numbers, spaces and dashes only).',
            'metro.in' => 'Choose your municipality from the list.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['nickname', 'account_number', 'address'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}
