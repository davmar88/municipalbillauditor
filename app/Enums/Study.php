<?php

namespace App\Enums;

/**
 * What a faction can study to grow stronger: one study at a time, up to
 * level 5, paid from the treasury and the store. Levels 3 to 5 need a crop
 * that only grows on the farms beyond the fences. Studies stay from season
 * to season.
 */
enum Study: string
{
    case Attack = 'attack';
    case Defence = 'defence';
    case Science = 'science';
    case Farming = 'farming';
    case Marching = 'marching';

    public const MAX_LEVEL = 5;

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** The study's strength at a level, as a fraction: 0.1 is 10%. */
    public function bonus(int $level): float
    {
        $level = max(0, min($level, self::MAX_LEVEL));

        return match ($this) {
            self::Attack, self::Defence, self::Farming => 0.05 * $level,
            self::Science, self::Marching => 0.1 * $level,
        };
    }

    /** What it does at a level, in a sentence. */
    public function does(int $level): string
    {
        $percent = (int) round($this->bonus($level) * 100).'%';

        return match ($this) {
            self::Attack => "Your raids hit {$percent} harder.",
            self::Defence => "Your land, and your members at the gate on Gate Night, hold {$percent} harder.",
            self::Science => "Every study takes {$percent} less time.",
            self::Farming => "Members gather {$percent} more.",
            self::Marching => "Your raids land {$percent} sooner.",
        };
    }

    /** @return array{corn: int, materials: array<string, int>} */
    public function cost(int $level): array
    {
        $level = max(1, min($level, self::MAX_LEVEL));

        [$material, $crop] = match ($this) {
            self::Attack => ['wood', 'iron'],
            self::Defence => ['stone', 'iron'],
            self::Science => ['dye', 'herbs'],
            self::Farming => ['straw', 'honey'],
            self::Marching => ['eggs', 'honey'],
        };

        $materials = [$material => [20, 35, 50, 70, 90][$level - 1]];

        if ($level >= 3) {
            $materials[$crop] = [10, 20, 35][$level - 3];
        }

        return ['corn' => [60, 150, 300, 500, 800][$level - 1], 'materials' => $materials];
    }

    /** How long a level takes to study, before Science. */
    public function minutes(int $level): int
    {
        return [60, 180, 360, 600, 900][max(1, min($level, self::MAX_LEVEL)) - 1];
    }
}
