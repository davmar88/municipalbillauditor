<?php

namespace App\Game;

use App\Enums\ItemTemplate;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Market as an exchange: every design trading in a valley, with how
 * many copies are in circulation, how many sold this week and in all, the
 * last price and how it moved against the week before, and what's on the
 * shelves now. Also each kind of item added up, and the best and worst
 * sellers.
 */
final class Exchange
{
    /** How many days count as "this week". */
    public const WEEK_DAYS = 7;

    /**
     * Every design with copies made in the valley.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function board(Valley $valley): Collection
    {
        $now = CarbonImmutable::now();
        $week = $now->subDays(self::WEEK_DAYS);
        $lastWeek = $now->subDays(self::WEEK_DAYS * 2);

        $designs = DB::table('item_designs')
            ->join('players as makers', 'makers.id', '=', 'item_designs.player_id')
            ->where('makers.valley_id', $valley->id)
            ->where('item_designs.status', 'approved')
            ->where('item_designs.made', '>', 0)
            ->get(['item_designs.id', 'item_designs.name', 'item_designs.template', 'item_designs.colours', 'item_designs.pattern', 'item_designs.made', 'makers.name as maker']);

        if ($designs->isEmpty()) {
            return collect();
        }

        $ids = $designs->pluck('id');

        // Copies still about: made and not used up.
        $circulating = DB::table('items')->whereIn('item_design_id', $ids)->whereNull('used_at')
            ->groupBy('item_design_id')->selectRaw('item_design_id, COUNT(*) AS total')->pluck('total', 'item_design_id');

        $sales = DB::table('listings')
            ->join('items', 'items.id', '=', 'listings.item_id')
            ->whereIn('items.item_design_id', $ids)
            ->whereNotNull('listings.sold_at')
            ->orderBy('listings.sold_at')
            ->get(['items.item_design_id as design', 'listings.price', 'listings.currency', 'listings.sold_at'])
            ->groupBy('design');

        $asks = DB::table('listings')
            ->join('items', 'items.id', '=', 'listings.item_id')
            ->whereIn('items.item_design_id', $ids)
            ->whereNull('listings.sold_at')
            ->groupBy('items.item_design_id', 'listings.currency')
            ->selectRaw('items.item_design_id AS design, listings.currency, COUNT(*) AS total, MIN(listings.price) AS lowest')
            ->get()
            ->groupBy('design');

        return $designs->map(function (object $design) use ($circulating, $sales, $asks, $week, $lastWeek) {
            $sold = $sales->get($design->id) ?? collect();
            $last = $sold->last();
            $currency = $last->currency ?? 'corn';
            $same = $sold->where('currency', $currency);
            $thisWeek = $same->filter(fn (object $sale) => CarbonImmutable::parse($sale->sold_at)->gte($week));
            $before = $same->filter(fn (object $sale) => CarbonImmutable::parse($sale->sold_at)->between($lastWeek, $week));
            $average = $thisWeek->isEmpty() ? null : round($thisWeek->avg('price'), 1);
            $was = $before->isEmpty() ? null : $before->avg('price');
            $onSale = ($asks->get($design->id) ?? collect())->sortBy('lowest')->first();
            $template = ItemTemplate::tryFrom($design->template);

            return [
                'id' => (int) $design->id,
                'name' => $design->name,
                'template' => $design->template,
                'kind' => $template?->label() ?? $design->template,
                'colours' => json_decode((string) $design->colours, true) ?: [],
                'pattern' => $design->pattern,
                'maker' => $design->maker,
                'made' => (int) $design->made,
                'circulating' => (int) ($circulating[$design->id] ?? 0),
                'sold' => $sold->count(),
                'soldWeek' => $sold->filter(fn (object $sale) => CarbonImmutable::parse($sale->sold_at)->gte($week))->count(),
                'last' => $last ? ['price' => (int) $last->price, 'currency' => $currency, 'at' => CarbonImmutable::parse($last->sold_at)->toIso8601String()] : null,
                'average' => $average,
                // How the week's average moved against the week before, in percent.
                'change' => $average !== null && $was ? round(($average - $was) / $was * 100) : null,
                'onSale' => $onSale ? ['count' => (int) $onSale->total, 'lowest' => (int) $onSale->lowest, 'currency' => $onSale->currency] : null,
            ];
        })->values();
    }

    /**
     * Each kind of item added up: copies about and copies sold.
     *
     * @param  Collection<int, array<string, mixed>>  $board
     * @return list<array{kind: string, template: string, circulating: int, sold: int, soldWeek: int, designs: int}>
     */
    public static function kinds(Collection $board): array
    {
        return $board->groupBy('template')->map(fn (Collection $designs, string $template) => [
            'kind' => $designs->first()['kind'],
            'template' => $template,
            'circulating' => (int) $designs->sum('circulating'),
            'sold' => (int) $designs->sum('sold'),
            'soldWeek' => (int) $designs->sum('soldWeek'),
            'designs' => $designs->count(),
        ])->sortByDesc('sold')->values()->all();
    }
}
