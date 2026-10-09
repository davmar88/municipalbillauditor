<?php

namespace App\Audit;

use App\Enums\ReadingType;
use App\Enums\Service;
use App\Models\Bill;
use App\Models\LineItem;
use App\Models\Outage;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * Everything a rule needs to audit one bill: the bill and its line items,
 * the property, the property's earlier bills ("history", most recent
 * first) and its recorded outages.
 */
final class AuditContext
{
    /**
     * @param  Collection<int, LineItem>  $lineItems
     * @param  Collection<int, Bill>  $history  most recent first, each with lineItems loaded
     * @param  Collection<int, Outage>  $outages
     * @param  Bill|null  $next  the bill immediately after this one, if any
     */
    public function __construct(
        public readonly Bill $bill,
        public readonly Property $property,
        public readonly Collection $lineItems,
        public readonly Collection $history,
        public readonly Collection $outages,
        public readonly ?Bill $next = null,
    ) {}

    /**
     * @param  Collection<int, Bill>  $propertyBills  all of the property's bills, with lineItems loaded
     * @param  Collection<int, Outage>  $outages
     */
    public static function build(Bill $bill, Property $property, Collection $propertyBills, Collection $outages): self
    {
        $sortKey = fn (Bill $b) => [($b->period_end ?? $b->bill_date)?->toDateString() ?? '', $b->id];

        $history = $propertyBills
            ->filter(fn (Bill $other) => $other->id !== $bill->id && self::isBefore($other, $bill))
            ->sort(fn (Bill $a, Bill $b) => $sortKey($b) <=> $sortKey($a))
            ->values();

        $next = $propertyBills
            ->filter(fn (Bill $other) => $other->id !== $bill->id && self::isBefore($bill, $other))
            ->sort(fn (Bill $a, Bill $b) => $sortKey($a) <=> $sortKey($b))
            ->first();

        return new self($bill, $property, $bill->lineItems, $history, $outages, $next);
    }

    /**
     * History: bills whose period ends on or before this bill's period
     * starts or, when periods are missing, with an earlier bill date.
     */
    public static function isBefore(Bill $other, Bill $bill): bool
    {
        if ($bill->period_start !== null && $other->period_end !== null) {
            // A shared boundary day (one period ends on the reading date the
            // next one starts) still counts as "before".
            return $other->period_end->lte($bill->period_start)
                && ($other->period_start === null || $other->period_start->lt($bill->period_start));
        }

        if ($bill->bill_date !== null && $other->bill_date !== null) {
            return $other->bill_date->lt($bill->bill_date);
        }

        return false;
    }

    public function usage(Service $service, ?ReadingType $only = null): ServiceUsage
    {
        $lines = $only === null
            ? $this->lineItems
            : $this->lineItems->filter(fn (LineItem $l) => $l->reading_type === $only);

        return ServiceUsage::fromLines($service, $lines, $this->bill->id);
    }

    /**
     * The service's usage on each history bill, most recent first.
     *
     * @return Collection<int, ServiceUsage>
     */
    public function historyUsages(Service $service): Collection
    {
        return $this->history
            ->map(fn (Bill $bill) => ServiceUsage::fromLines($service, $bill->lineItems, $bill->id))
            ->values();
    }

    /**
     * Previous bills that have a usage figure for the service.
     *
     * @return Collection<int, ServiceUsage>
     */
    public function historyWithUsage(Service $service, int $limit): Collection
    {
        return $this->historyUsages($service)
            ->filter(fn (ServiceUsage $u) => $u->hasUsage())
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, Outage>
     */
    public function outagesFor(Service $service): Collection
    {
        return $this->outages->filter(fn (Outage $o) => $o->service === $service)->values();
    }
}
