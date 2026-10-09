<?php

namespace App\Http\Requests;

class UpdatePropertyRequest extends StorePropertyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_map(
            fn (array $rules) => ['sometimes', ...$rules],
            parent::rules(),
        );
    }
}
