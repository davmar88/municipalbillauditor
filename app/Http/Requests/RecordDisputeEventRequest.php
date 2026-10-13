<?php

namespace App\Http\Requests;

use App\Enums\DisputeEventKind;
use App\Models\Dispute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Something that happened to a dispute: a reference number arrived, the
 * municipality replied, it was escalated, or the credit showed up.
 */
class RecordDisputeEventRequest extends FormRequest
{
    /** What a customer, or our team on their behalf, can record. */
    public const KINDS = [
        DisputeEventKind::Acknowledged,
        DisputeEventKind::ReplyReceived,
        DisputeEventKind::EscalatedCouncillor,
        DisputeEventKind::EscalatedPublicProtector,
        DisputeEventKind::CreditReceived,
        DisputeEventKind::Closed,
        DisputeEventKind::Note,
    ];

    public function rules(): array
    {
        /** @var Dispute $dispute */
        $dispute = $this->route('dispute');

        return [
            'kind' => ['required', Rule::in(array_map(fn (DisputeEventKind $k) => $k->value, self::KINDS))],
            'happened_on' => ['required', 'date', 'before_or_equal:today', ...($dispute->lodged_on ? ['after_or_equal:'.$dispute->lodged_on->toDateString()] : [])],
            'reference' => ['nullable', 'required_if:kind,acknowledged', 'string', 'max:60'],
            'amount' => ['nullable', 'required_if:kind,credit_received', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'happened_on.after_or_equal' => 'That date is before the dispute was lodged.',
            'reference.required_if' => 'Enter the reference number the municipality gave you.',
            'amount.required_if' => 'Enter the amount credited to the account.',
        ];
    }

    /**
     * @return array{reference: ?string, amount: ?string, note: ?string}
     */
    public function details(): array
    {
        return [
            'reference' => $this->validated('reference'),
            'amount' => $this->validated('amount'),
            'note' => $this->validated('note'),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (DisputeEventKind $k) => ['value' => $k->value, 'label' => $k->label()], self::KINDS);
    }
}
