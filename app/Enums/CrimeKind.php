<?php

namespace App\Enums;

/** The crimes: against factions, their farms, homes, and other animals. */
enum CrimeKind: string
{
    case Loot = 'loot';
    case Burn = 'burn';
    case Plunder = 'plunder';
    case Rob = 'rob';
    case Attack = 'attack';

    public function label(): string
    {
        return match ($this) {
            self::Loot => 'Loot a faction',
            self::Burn => 'Burn down a home',
            self::Plunder => 'Plunder a farm',
            self::Rob => 'Rob an animal',
            self::Attack => 'Attack an animal',
        };
    }

    public function horseshoes(): int
    {
        return match ($this) {
            self::Loot => 2,
            self::Burn => 3,
            self::Plunder => 2,
            self::Rob => 2,
            self::Attack => 2,
        };
    }
}
