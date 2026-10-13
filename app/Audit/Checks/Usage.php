<?php

namespace App\Audit\Checks;

use App\Audit\Data\BillSnapshot;
use App\Audit\Data\CheckContext;
use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use App\Audit\Money;
use Brick\Math\BigDecimal;

/**
 * Usage figures the checks share: how much was used, what is usual, and the
 * rate a bill charged.
 */
final class Usage
{
    /** How many earlier bills count towards usual use. */
    public const HISTORY_WINDOW = 12;

    /** Actual readings needed before "usual use" means anything. */
    public const MIN_HISTORY = 2;

    /**
     * Total use of a service on a bill, and whether it was read or estimated.
     *
     * @return array{0: ?BigDecimal, 1: ReadingType}|null
     */
    public static function of(BillSnapshot $bill, Service $service, string $meterNumber = ''): ?array
    {
        $readings = $bill->readingsFor($service, $meterNumber);

        if ($readings === []) {
            return null;
        }

        $total = BigDecimal::zero();

        foreach ($readings as $reading) {
            $used = $reading->used();

            if ($used === null) {
                $total = null;
                break;
            }

            $total = $total->plus($used);
        }

        $types = array_values(array_unique(array_map(fn ($r) => $r->type->value, $readings)));

        $kind = match (true) {
            in_array(ReadingType::Estimated->value, $types, true) => ReadingType::Estimated,
            $types === [ReadingType::Actual->value] => ReadingType::Actual,
            default => ReadingType::Unknown,
        };

        return [$total, $kind];
    }

    /**
     * Earlier bills that show this service, newest first.
     *
     * @return list<array{bill: BillSnapshot, used: ?BigDecimal, type: ReadingType}>
     */
    public static function history(CheckContext $ctx, Service $service, string $meterNumber = ''): array
    {
        $rows = [];

        foreach (array_slice($ctx->history, 0, self::HISTORY_WINDOW) as $past) {
            $usage = self::of($past, $service, $meterNumber);

            if ($usage !== null) {
                $rows[] = ['bill' => $past, 'used' => $usage[0], 'type' => $usage[1]];
            }
        }

        return $rows;
    }

    /**
     * Average actual use over recent bills, rounded to the shown precision.
     *
     * @return array{0: BigDecimal, 1: int}|null The average and how many bills it is based on.
     */
    public static function usual(CheckContext $ctx, Service $service, string $meterNumber = '', int $minimum = self::MIN_HISTORY): ?array
    {
        $actual = [];

        foreach (self::history($ctx, $service, $meterNumber) as $row) {
            if ($row['type'] === ReadingType::Actual && $row['used'] !== null) {
                $actual[] = $row['used'];
            }
        }

        if (count($actual) < $minimum) {
            return null;
        }

        $average = Money::divide(Money::sum($actual), count($actual), $service->quantityScale());

        return [$average, count($actual)];
    }

    /**
     * This bill plus the run of earlier bills that were also estimated.
     *
     * @return list<BillSnapshot>
     */
    public static function estimateStreak(CheckContext $ctx, Service $service, string $meterNumber = ''): array
    {
        $streak = [$ctx->bill];

        foreach (self::history($ctx, $service, $meterNumber) as $row) {
            if ($row['type'] !== ReadingType::Estimated) {
                break;
            }

            $streak[] = $row['bill'];
        }

        return $streak;
    }

    /**
     * The most recent earlier bill with an actual reading: its date and meter value.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?BigDecimal}|null
     */
    public static function lastActual(CheckContext $ctx, Service $service, string $meterNumber = ''): ?array
    {
        foreach ($ctx->history as $past) {
            $readings = $past->readingsFor($service, $meterNumber);

            $notActual = array_filter($readings, fn ($r) => $r->type !== ReadingType::Actual);

            if ($readings !== [] && $notActual === []) {
                return [$past->billDate, $readings[0]->current];
            }
        }

        return null;
    }

    /**
     * The bill's average rate per unit for a service, rounded to the cent.
     *
     * Uses the consumption lines when the bill gives quantities, otherwise the
     * service's whole charge over the units billed. On a stepped tariff the
     * average understates the top steps, so amounts based on it are cautious.
     */
    public static function rate(BillSnapshot $bill, Service $service, ?BigDecimal $consumption): ?BigDecimal
    {
        $lines = array_values(array_filter(
            $bill->charges,
            fn ($c) => $c->service === $service && $c->amount->isPositive(),
        ));

        if ($lines === []) {
            return null;
        }

        $withQuantity = array_values(array_filter($lines, fn ($c) => $c->quantity !== null && $c->quantity->isPositive()));

        if ($withQuantity !== []) {
            $amount = Money::sum(array_map(fn ($c) => $c->amount, $withQuantity));
            $quantity = Money::sum(array_map(fn ($c) => $c->quantity, $withQuantity));
        } else {
            if ($consumption === null || ! $consumption->isPositive()) {
                return null;
            }

            $amount = Money::sum(array_map(fn ($c) => $c->amount, $lines));
            $quantity = $consumption;
        }

        return Money::divide($amount, $quantity, 2);
    }
}
