<?php

namespace App\Enums;

/** What lone wolves can do to factions. */
enum CrimeKind: string
{
    case Loot = 'loot';
    case Burn = 'burn';
    case Plunder = 'plunder';

    public function label(): string
    {
        return match ($this) {
            self::Loot => 'Loot a faction',
            self::Burn => 'Burn down a home',
            self::Plunder => 'Plunder a farm',
        };
    }

    public function horseshoes(): int
    {
        return match ($this) {
            self::Loot => 2,
            self::Burn => 3,
            self::Plunder => 2,
        };
    }
}
