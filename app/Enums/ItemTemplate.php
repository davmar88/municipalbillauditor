<?php

namespace App\Enums;

/** The shapes players design items on, and what one copy costs to make. */
enum ItemTemplate: string
{
    case Hat = 'hat';
    case Scarf = 'scarf';
    case Sign = 'sign';

    public function label(): string
    {
        return match ($this) {
            self::Hat => 'Hat',
            self::Scarf => 'Scarf',
            self::Sign => 'Shop sign',
        };
    }

    /** @return array<string, int> */
    public function materials(): array
    {
        return match ($this) {
            self::Hat => ['straw' => 3, 'dye' => 1],
            self::Scarf => ['wool' => 4, 'dye' => 1],
            self::Sign => ['wood' => 3, 'clay' => 1],
        };
    }
}
