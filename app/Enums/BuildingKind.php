<?php

namespace App\Enums;

/**
 * What a faction can build on its land. The Den is the faction's base and
 * comes first. The Head Office goes in the Farmhouse, and its faction rules
 * the valley once it stands. Each building goes up to level 3; costs come
 * out of the faction's treasury and its store of materials.
 */
enum BuildingKind: string
{
    case Den = 'den';
    case Watchtower = 'watchtower';
    case DrillYard = 'drill_yard';
    case Storehouse = 'storehouse';
    case Workshop = 'workshop';
    case HeadOffice = 'head_office';

    public const MAX_LEVEL = 3;

    public function label(): string
    {
        return match ($this) {
            self::Den => 'Den',
            self::Watchtower => 'Watchtower',
            self::DrillYard => 'Drill Yard',
            self::Storehouse => 'Storehouse',
            self::Workshop => 'Workshop',
            self::HeadOffice => 'Head Office',
        };
    }

    /** What it does at a level, in a sentence. */
    public function does(int $level): string
    {
        $percent = (int) round($this->bonus($level) * 100).'%';
        ['corn' => $corn, 'material' => $material] = $this->storage($level);

        return $this->purpose($level, $percent)." Stores {$corn} Corn and {$material} of each material.";
    }

    private function purpose(int $level, string $percent): string
    {
        return match ($this) {
            self::Den => "The faction's base. Defends its region {$percent} harder and makes room for {$this->extraMembers($level)} more members.",
            self::Watchtower => "Defends its region {$percent} harder.",
            self::DrillYard => "Raids from here on the regions next door hit {$percent} harder.",
            self::Storehouse => 'Looters get away with '.(int) round($this->lootShare($level) * 100).'% of the treasury instead of 10%.',
            self::Workshop => "Members gather {$percent} more materials here.",
            self::HeadOffice => "Your faction rules Hollowmere from here once it stands. Defends the Farmhouse {$percent} harder.",
        };
    }

    /** The building's strength at a level, as a fraction: 0.2 is 20% harder or more. */
    public function bonus(int $level): float
    {
        if ($level < 1) {
            return 0.0;
        }

        $level = min($level, self::MAX_LEVEL);

        return match ($this) {
            self::Den => [0.2, 0.35, 0.5][$level - 1],
            self::Watchtower => [0.3, 0.45, 0.6][$level - 1],
            self::DrillYard => [0.1, 0.2, 0.3][$level - 1],
            self::Storehouse => 0.1 - $this->lootShare($level),
            self::Workshop => [0.25, 0.5, 0.75][$level - 1],
            self::HeadOffice => [0.2, 0.35, 0.5][$level - 1],
        };
    }

    /**
     * What it stores at a level: Corn, and of each material. The Den and
     * the Storehouse hold the most; raiders who take it carry this share off.
     *
     * @return array{corn: int, material: int}
     */
    public function storage(int $level): array
    {
        if ($level < 1) {
            return ['corn' => 0, 'material' => 0];
        }

        $level = min($level, self::MAX_LEVEL);

        [$corn, $material] = match ($this) {
            self::Den => [[500, 150], [1000, 300], [2000, 600]][$level - 1],
            self::Storehouse => [[1000, 300], [2000, 600], [4000, 1200]][$level - 1],
            self::HeadOffice => [[800, 250], [1500, 450], [2500, 750]][$level - 1],
            default => [150, 50],
        };

        return ['corn' => $corn, 'material' => $material];
    }

    public function extraMembers(int $level): int
    {
        return $this === self::Den ? 2 * max(0, min($level, self::MAX_LEVEL)) : 0;
    }

    /** The share of the treasury a looter takes when a Storehouse stands. */
    public function lootShare(int $level): float
    {
        return $this === self::Storehouse && $level >= 1 ? [0.06, 0.04, 0.02][min($level, self::MAX_LEVEL) - 1] : 0.1;
    }

    /** @return array{corn: int, materials: array<string, int>} */
    public function cost(int $level): array
    {
        $costs = match ($this) {
            self::Den => [
                [80, ['wood' => 20, 'straw' => 20]],
                [200, ['wood' => 30, 'stone' => 25, 'eggs' => 15]],
                [400, ['stone' => 50, 'clay' => 40, 'wool' => 20]],
            ],
            self::Watchtower => [
                [40, ['wood' => 25]],
                [100, ['wood' => 20, 'stone' => 25]],
                [200, ['stone' => 50, 'wool' => 10]],
            ],
            self::DrillYard => [
                [50, ['straw' => 20, 'wood' => 10]],
                [120, ['straw' => 30, 'wood' => 20]],
                [240, ['wood' => 30, 'stone' => 30]],
            ],
            self::Storehouse => [
                [30, ['clay' => 20, 'wood' => 10]],
                [80, ['clay' => 30, 'eggs' => 15]],
                [160, ['clay' => 40, 'stone' => 20]],
            ],
            self::Workshop => [
                [40, ['wood' => 15, 'dye' => 10]],
                [100, ['wood' => 25, 'dye' => 20]],
                [200, ['wood' => 30, 'dye' => 30, 'wool' => 15]],
            ],
            self::HeadOffice => [
                [300, ['wood' => 40, 'stone' => 40, 'clay' => 20]],
                [500, ['stone' => 60, 'clay' => 40, 'wool' => 20]],
                [800, ['stone' => 90, 'wood' => 60, 'dye' => 30]],
            ],
        };

        [$corn, $materials] = $costs[max(1, min($level, self::MAX_LEVEL)) - 1];

        return ['corn' => $corn, 'materials' => $materials];
    }

    /**
     * Everything the game screens show about a building, level by level.
     *
     * @return array{value: string, label: string, levels: list<array{level: int, does: string, corn: int, materials: array<string, int>, minutes: int}>}
     */
    public function card(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'levels' => array_map(fn (int $level) => [
                'level' => $level,
                'does' => $this->does($level),
                ...$this->cost($level),
                'minutes' => $this->minutes($level),
            ], range(1, self::MAX_LEVEL)),
        ];
    }

    /** How long a level takes to build. */
    public function minutes(int $level): int
    {
        $level = max(1, min($level, self::MAX_LEVEL));

        // A Head Office takes longest: the valley can fight for the Farmhouse meanwhile.
        return $this === self::HeadOffice ? [120, 240, 480][$level - 1] : [60, 180, 360][$level - 1];
    }
}
