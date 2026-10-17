<?php

namespace App\Game;

use Carbon\CarbonImmutable;

/**
 * Spot the Edit: the free daily puzzle. A Wall of five laws shows for 15
 * seconds, then again with one number changed, and the player taps the
 * change. The same date always makes the same puzzle, so everyone plays
 * the same one and can compare.
 */
final class Puzzle
{
    public const FIRST_DAY = '2026-10-17';

    private const FACTIONS = ['The Red Hooves', 'The Hayfork Union', 'The Lantern Club', 'The Quiet Burrow', 'The Millstones'];

    /**
     * @return array{number: int, date: string, before: array<int, string>, after: array<int, string>, answer: int}
     */
    public function forDate(string $date): array
    {
        $seed = crc32('spot-the-edit:'.$date);
        $templates = [LawTemplate::GrainTax, LawTemplate::MarketLevy, LawTemplate::Rations, LawTemplate::Curfew, LawTemplate::TradeBan];
        $values = [];

        foreach ($templates as $index => $template) {
            $values[$index] = $this->value($template, $seed, (string) $index);
        }

        $answer = (int) floor(Dice::roll($seed, 'slot', 0, 3.999));
        $changed = $values;
        [$min, $max] = $templates[$answer]->range();
        $step = 1 + (int) floor(Dice::roll($seed, 'step', 0, 2.999));
        $up = Dice::roll($seed, 'up') >= 0.5;
        $new = $up ? $values[$answer] + $step : $values[$answer] - $step;

        if ($new < $min || $new > $max) {
            $new = $up ? $values[$answer] - $step : $values[$answer] + $step;
        }

        $changed[$answer] = max($min, min($max, $new));

        if ($changed[$answer] === $values[$answer]) {
            $changed[$answer] = $values[$answer] === $max ? $max - 1 : $values[$answer] + 1;
        }

        $faction = self::FACTIONS[(int) floor(Dice::roll($seed, 'faction', 0, count(self::FACTIONS) - 0.001))];

        return [
            'number' => (int) CarbonImmutable::parse(self::FIRST_DAY)->diffInDays(CarbonImmutable::parse($date)) + 1,
            'date' => $date,
            'before' => $this->wording($templates, $values, $faction),
            'after' => $this->wording($templates, $changed, $faction),
            'answer' => $answer,
        ];
    }

    private function value(LawTemplate $template, int $seed, string $salt): ?int
    {
        $range = $template->range();

        if ($range === null) {
            return null;
        }

        [$min, $max] = $range;

        return $min + (int) floor(Dice::roll($seed, $salt, 0, $max - $min + 0.999));
    }

    /**
     * @param  array<int, LawTemplate>  $templates
     * @param  array<int, int|null>  $values
     * @return array<int, string>
     */
    private function wording(array $templates, array $values, string $faction): array
    {
        return array_map(function (LawTemplate $template, ?int $value) use ($faction) {
            return $template === LawTemplate::TradeBan
                ? "{$faction} may not trade in the market."
                : $template->wording($value, null);
        }, $templates, $values);
    }
}
