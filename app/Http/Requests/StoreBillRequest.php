<?php

namespace App\Http\Requests;

use App\Enums\OrganisationKind;
use App\Models\Property;
use App\Services\BillIntake;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillRequest extends FormRequest
{
    public function rules(): array
    {
        $organisation = $this->user()->organisation;
        $firstHouseholdBill = $organisation->kind === OrganisationKind::Household && ! $organisation->properties()->exists();

        return [
            'bill' => [
                'required',
                'file',
                'mimetypes:'.implode(',', BillIntake::ACCEPTED_MIME_TYPES),
                'max:'.config('auditor.max_upload_kb'),
            ],
            'property_id' => ['nullable', Rule::exists('properties', 'id')->where('organisation_id', $organisation->id)],
            'metro_id' => [Rule::requiredIf($firstHouseholdBill), 'nullable', Rule::exists('metros', 'id')->where('live', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'bill.required' => 'Choose a photo or PDF of your bill.',
            'bill.mimetypes' => 'Send a photo (JPEG, PNG, WebP) or a PDF of the bill.',
            'bill.max' => 'That file is too big. Try a smaller photo or the PDF.',
            'metro_id.required' => 'Choose the municipality that bills you.',
        ];
    }

    public function property(): ?Property
    {
        return $this->filled('property_id') ? Property::find($this->integer('property_id')) : null;
    }
}
