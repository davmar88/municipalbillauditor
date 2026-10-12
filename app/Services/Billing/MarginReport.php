<?php

namespace App\Services\Billing;

use App\Audit\Money;
use App\Enums\CreditKind;
use App\Enums\FeeStatus;
use App\Models\ClaudeUsageRecord;
use App\Models\CreditTransaction;
use App\Models\FeeInvoice;
use App\Models\Organisation;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * One month's money in against what Claude cost us. Revenue is counted
 * when it is received: top-ups (by card or EFT) and monthly fees paid.
 */
class MarginReport
{
    /**
     * @return array<string, mixed>
     */
    public function forMonth(CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        $topUps = Money::sum(CreditTransaction::query()
            ->where('kind', CreditKind::TopUp)
            ->whereBetween('created_at', [$from, $to])
            ->pluck('amount')
            ->map(fn ($a) => Money::of($a)));

        $fees = Money::sum(FeeInvoice::query()
            ->where('status', FeeStatus::Paid)
            ->whereBetween('paid_at', [$from, $to])
            ->pluck('amount')
            ->map(fn ($a) => Money::of($a)));

        $usage = ClaudeUsageRecord::query()->whereBetween('created_at', [$from, $to])->get();
        $cost = Money::sum($usage->pluck('cost_zar')->filter()->map(fn ($c) => Money::of($c)));
        $revenue = $topUps->plus($fees);
        $margin = $revenue->minus($cost);

        $byOrganisation = $usage->groupBy('organisation_id')
            ->map(fn ($rows, $id) => [
                'organisationId' => $id === '' ? null : (int) $id,
                'cost' => Money::sum($rows->pluck('cost_zar')->filter()->map(fn ($c) => Money::of($c))),
                'calls' => $rows->count(),
            ])
            ->sortByDesc(fn (array $row) => (float) (string) $row['cost'])
            ->take(10)
            ->values();
        $names = Organisation::query()->whereIn('id', $byOrganisation->pluck('organisationId')->filter())->pluck('name', 'id');

        return [
            'month' => $from->format('Y-m'),
            'monthLabel' => $from->format('F Y'),
            'topUps' => Money::format($topUps),
            'monthlyFees' => Money::format($fees),
            'revenue' => Money::format($revenue),
            'claudeCost' => Money::format(Money::money($cost)),
            'margin' => Money::format(Money::money($margin)),
            'marginPercent' => $revenue->isZero() ? null : (string) $margin->multipliedBy(100)->dividedBy($revenue, 1, RoundingMode::HalfUp).'%',
            'calls' => $usage->count(),
            'unpricedCalls' => $usage->whereNull('cost_zar')->count(),
            'byPurpose' => $usage->groupBy('purpose')->map(fn ($rows, $purpose) => [
                'purpose' => $purpose,
                'calls' => $rows->count(),
                'cost' => Money::format(Money::money(Money::sum($rows->pluck('cost_zar')->filter()->map(fn ($c) => Money::of($c))))),
                'perCall' => Money::format(Money::of(Money::sum($rows->pluck('cost_zar')->filter()->map(fn ($c) => Money::of($c))))->dividedBy(max(1, $rows->count()), 2, RoundingMode::HalfUp)),
            ])->sortKeys()->values(),
            'byOrganisation' => $byOrganisation->map(fn (array $row) => [
                'name' => $row['organisationId'] !== null ? ($names[$row['organisationId']] ?? 'Deleted account') : 'No account',
                'calls' => $row['calls'],
                'cost' => Money::format(Money::money($row['cost'])),
            ]),
        ];
    }

    /**
     * The months staff can pick, newest first.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function months(int $count = 12): array
    {
        $month = CarbonImmutable::now()->startOfMonth();

        return array_map(fn (int $ago) => [
            'value' => $month->subMonthsNoOverflow($ago)->format('Y-m'),
            'label' => $month->subMonthsNoOverflow($ago)->format('F Y'),
        ], range(0, $count - 1));
    }
}
