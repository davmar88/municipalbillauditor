<?php

namespace Tests\Support;

use App\Audit\AuditContext;
use App\Http\Requests\Concerns\LineItemRules;
use App\Models\Bill;
use App\Models\LineItem;
use App\Models\Outage;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds a property with bills and outages in memory (no database) so audit
 * rules can be unit tested.
 */
final class AuditScenario
{
    public Property $property;

    /** @var Collection<int, Bill> */
    public Collection $bills;

    /** @var Collection<int, Outage> */
    public Collection $outages;

    private int $nextLineId = 1;

    public function __construct(string $propertyType = 'residential')
    {
        $this->property = new Property([
            'nickname' => 'Test property',
            'metro' => 'johannesburg',
            'account_number' => '5501234567',
            'address' => '1 Test Rd',
            'property_type' => $propertyType,
        ]);
        $this->property->id = 1;
        $this->bills = collect();
        $this->outages = collect();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $attributes
     */
    public function bill(?string $periodStart, ?string $periodEnd, array $lines, array $attributes = []): Bill
    {
        $bill = new Bill([
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'bill_date' => $periodEnd !== null ? CarbonImmutable::parse($periodEnd)->addDays(5)->toDateString() : null,
            'status' => 'audited',
            'extraction_source' => 'manual',
            ...$attributes,
        ]);
        $bill->id = $this->bills->count() + 1;
        $bill->property_id = $this->property->id;
        $bill->setRelation('property', $this->property);

        $items = collect($lines)->map(function (array $line) {
            $item = new LineItem(LineItemRules::normalise([
                'reading_type' => 'actual',
                'amount_cents' => 0,
                ...$line,
            ]));
            $item->id = $this->nextLineId++;

            return $item;
        });
        $bill->setRelation('lineItems', $items);

        $this->bills->push($bill);

        return $bill;
    }

    /**
     * One bill per calendar month starting January 2026.
     *
     * @param  list<list<array<string, mixed>>>  $linesPerBill
     * @return list<Bill>
     */
    public function monthly(array $linesPerBill, int $startMonth = 1): array
    {
        $bills = [];
        foreach ($linesPerBill as $offset => $lines) {
            $start = CarbonImmutable::create(2026, $startMonth + $offset, 1);
            $bills[] = $this->bill($start->toDateString(), $start->endOfMonth()->toDateString(), $lines);
        }

        return $bills;
    }

    /**
     * @param  list<float>  $history
     * @param  array<string, mixed>  $latest
     */
    public function usageSeries(string $service, array $history, array $latest, string $readingType = 'actual', int $centsPerUnit = 3000): Bill
    {
        $unit = $service === 'water' ? 'kl' : 'kwh';
        $lines = array_map(fn (float $usage) => [[
            'service' => $service,
            'reading_type' => $readingType,
            'consumption' => $usage,
            'unit' => $unit,
            'amount_cents' => (int) round($usage * $centsPerUnit),
        ]], $history);

        $consumption = $latest['consumption'] ?? null;
        $lines[] = [[
            'service' => $service,
            'reading_type' => 'actual',
            'unit' => $unit,
            'amount_cents' => $consumption !== null ? (int) round($consumption * $centsPerUnit) : 10000,
            ...$latest,
        ]];

        $bills = $this->monthly($lines);

        return end($bills);
    }

    public function outage(string $service, string $startsAt, string $endsAt): Outage
    {
        $outage = new Outage([
            'service' => $service,
            'starts_at' => CarbonImmutable::parse($startsAt)->utc(),
            'ends_at' => CarbonImmutable::parse($endsAt)->utc(),
        ]);
        $outage->id = $this->outages->count() + 1;
        $outage->property_id = $this->property->id;
        $this->outages->push($outage);

        return $outage;
    }

    public function context(Bill $bill): AuditContext
    {
        return AuditContext::build($bill, $this->property, $this->bills, $this->outages);
    }
}
