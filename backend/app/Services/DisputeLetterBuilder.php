<?php

namespace App\Services;

use App\Audit\AuditService;
use App\Audit\OverchargeTotals;
use App\Enums\FindingRule;
use App\Models\Bill;
use App\Models\Finding;
use App\Models\User;
use App\Support\Dates;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

/**
 * Generates the dispute letter from resources/views/letters/dispute.blade.php.
 */
class DisputeLetterBuilder
{
    /** Rules where an actual meter reading helps settle the dispute. */
    private const METER_READING_RULES = [
        FindingRule::EstimatedReading,
        FindingRule::ConsecutiveEstimates,
        FindingRule::ConsumptionSpike,
        FindingRule::OutageCharge,
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  Collection<int, Finding>  $findings
     * @return array{subject: string, body: string}
     */
    public function build(Bill $bill, Collection $findings, User $user): array
    {
        $bill->loadMissing(['property', 'lineItems']);
        $property = $bill->property;
        $metro = $property->metroConfig();

        $items = $findings->values()->map(fn (Finding $finding) => [
            'heading' => $this->heading($finding),
            'paragraph' => $this->audit->ruleFor($finding->rule)->letterParagraph($finding, $bill),
            'amount' => $finding->estimated_overcharge_cents !== null && $finding->estimated_overcharge_cents > 0
                ? Money::format($finding->estimated_overcharge_cents)
                : null,
        ]);

        $total = OverchargeTotals::sum($findings);
        $overlapping = $total < OverchargeTotals::naiveSum($findings);
        $someUnknown = $items->contains(fn (array $i) => $i['amount'] === null);

        $meterServices = $findings
            ->filter(fn (Finding $f) => in_array($f->rule, self::METER_READING_RULES, true) && $f->service !== null)
            ->map(fn (Finding $f) => $f->service->label())
            ->unique()
            ->values();

        $subject = 'Dispute of municipal account '.$property->account_number
            .($bill->bill_date !== null ? ': bill dated '.Dates::long($bill->bill_date) : '');

        $body = View::make('letters.dispute', [
            'date' => Dates::long(now()),
            'municipality' => $metro['name'],
            'accountNumber' => $property->account_number,
            'address' => $property->address,
            'billDate' => Dates::long($bill->bill_date),
            'periodStart' => Dates::long($bill->period_start),
            'periodEnd' => Dates::long($bill->period_end),
            'items' => $items->all(),
            'total' => $total > 0 ? Money::format($total) : null,
            'someUnknown' => $someUnknown,
            'overlapping' => $overlapping,
            'meterServices' => $meterServices->isEmpty() ? null : $meterServices->join(', ', ' and '),
            'meterPlural' => $meterServices->count() > 1,
            'name' => $user->name,
            'email' => $user->email,
        ])->render();

        return ['subject' => $subject, 'body' => $this->tidy($body)];
    }

    private function heading(Finding $finding): string
    {
        $service = $finding->service !== null ? ucfirst($finding->service->label()) : null;

        return match ($finding->rule) {
            FindingRule::EstimatedReading => "$service: estimated meter reading",
            FindingRule::ConsecutiveEstimates => "$service: repeated estimated readings",
            FindingRule::TariffMismatch => "$service: tariff category",
            FindingRule::OutageCharge => "$service: charges during a supply outage",
            FindingRule::ConsumptionSpike => "$service: unusually high consumption",
            FindingRule::ArithmeticMismatch => 'Bill total',
        };
    }

    /**
     * Normalises line endings and collapses runs of blank lines.
     */
    private function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text)."\n";
    }
}
