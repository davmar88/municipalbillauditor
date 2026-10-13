<?php

namespace App\Http\Requests;

use App\Models\Property;
use App\Services\BillIntake;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillRequest extends FormRequest
{
    public function rules(): array
    {
        $organisation = $this->user()->organisation;

        return [
            'bill' => [
                'required',
                'file',
                'mimetypes:'.implode(',', BillIntake::ACCEPTED_MIME_TYPES),
                'max:'.config('auditor.max_upload_kb'),
            ],
            'property_id' => ['nullable', Rule::exists('properties', 'id')->where('organisation_id', $organisation->id)],
            // Optional: we read the municipality from the bill.
            'municipality_id' => ['nullable', Rule::exists('municipalities', 'id')->where('live', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'bill.required' => 'Choose a photo or PDF of your bill.',
            'bill.mimetypes' => 'Send a photo (JPEG, PNG, WebP) or a PDF of the bill.',
            'bill.max' => 'That file is too big. Try a smaller photo or the PDF.',
        ];
    }

    public function property(): ?Property
    {
        return $this->filled('property_id') ? Property::find($this->integer('property_id')) : null;
    }
}
