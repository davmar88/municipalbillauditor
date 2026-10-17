<?php

namespace App\Enums;

/** What lone wolves can do to factions. */
enum CrimeKind: string
{
    case Loot = 'loot';
    case Burn = 'burn';

    public function label(): string
    {
        return match ($this) {
            self::Loot => 'Loot a faction',
            self::Burn => 'Burn down a home',
        };
    }

    public function horseshoes(): int
    {
        return match ($this) {
            self::Loot => 2,
            self::Burn => 3,
        };
    }
}
