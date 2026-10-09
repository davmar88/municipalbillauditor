<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LineItemRules;
use App\Support\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class StoreBillRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required_without:line_items', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:'.UploadLimits::BILL_FILE_MAX_KB],
            'bill_date' => ['nullable', 'date_format:Y-m-d'],
            'period_start' => ['nullable', 'date_format:Y-m-d'],
            'period_end' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'total_cents' => ['nullable', 'integer', 'min:-100000000000', 'max:100000000000'],
            // A file that was sent but failed to upload still counts as sent:
            // the user should hear about the file, not about line items.
            'line_items' => [Rule::requiredIf(fn () => ! $this->fileWasSent()), 'nullable', 'array', 'max:100'],
            ...LineItemRules::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required_without' => 'Add a photo or PDF of your bill, or enter its line items.',
            'line_items.required' => 'Add a photo or PDF of your bill, or enter its line items.',
            'file.uploaded' => $this->uploadFailedMessage(),
            'file.mimes' => 'Upload a photo (JPEG, PNG, WebP or HEIC) or a PDF of your bill.',
            'file.max' => UploadLimits::BILL_FILE_TOO_BIG,
            'line_items.array' => 'The line items could not be read. Send them as a list.',
        ];
    }

    /**
     * True when the request carried a file, even one PHP rejected (for
     * example because it was bigger than upload_max_filesize).
     */
    private function fileWasSent(): bool
    {
        return $this->file('file') instanceof UploadedFile;
    }

    private function uploadFailedMessage(): string
    {
        $file = $this->file('file');
        $error = $file instanceof UploadedFile ? $file->getError() : UPLOAD_ERR_OK;

        return in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? UploadLimits::BILL_FILE_TOO_BIG
            : 'Your file didn\'t upload. Please try again.';
    }

    /**
     * Multipart requests send line_items as a JSON-encoded string.
     */
    protected function prepareForValidation(): void
    {
        $lineItems = $this->input('line_items');
        if (is_string($lineItems)) {
            $decoded = trim($lineItems) === '' ? null : json_decode($lineItems, true);
            if (is_array($decoded) || trim($lineItems) === '') {
                $this->merge(['line_items' => $decoded]);
            }
        }

        foreach (['bill_date', 'period_start', 'period_end', 'due_date', 'total_cents'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $start = $this->input('period_start');
                $end = $this->input('period_end');
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
        $data = $this->safe()->except('file');
        if (isset($data['total_cents'])) {
            $data['total_cents'] = (int) $data['total_cents'];
        }

        return $data;
    }
}
