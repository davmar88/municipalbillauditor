<?php

namespace App\Enums;

/**
 * The shapes players design items on: what one copy costs to make and, for
 * the recipes with a power, what using a copy does. Looks are for wearing
 * and selling; a powered copy is used up when its owner uses it. Better
 * recipes open up as a maker's Creativity grows.
 */
enum ItemTemplate: string
{
    case Hat = 'hat';
    case Scarf = 'scarf';
    case Sign = 'sign';
    case Gloves = 'gloves';
    case Shoes = 'shoes';
    case Cloak = 'cloak';
    case Tonic = 'tonic';
    case Ribbon = 'ribbon';
    case Banner = 'banner';
    case Hedge = 'hedge';
    case Basket = 'basket';
    case Bell = 'bell';
    case Trowel = 'trowel';
    case Sheaf = 'sheaf';

    public function label(): string
    {
        return match ($this) {
            self::Hat => 'Hat',
            self::Scarf => 'Scarf',
            self::Sign => 'Shop sign',
            self::Gloves => 'Gathering Gloves',
            self::Shoes => 'Iron Shoes',
            self::Cloak => 'Night Cloak',
            self::Tonic => 'Willow Tonic',
            self::Ribbon => 'Prize Ribbon',
            self::Banner => 'War Banner',
            self::Hedge => 'Thorn Hedge',
            self::Basket => 'Feast Basket',
            self::Bell => 'Watch Bell',
            self::Trowel => "Mason's Trowel",
            self::Sheaf => 'Golden Sheaf',
        };
    }

    /** @return array<string, int> */
    public function materials(): array
    {
        return match ($this) {
            self::Hat => ['straw' => 3, 'dye' => 1],
            self::Scarf => ['wool' => 4, 'dye' => 1],
            self::Sign => ['wood' => 3, 'clay' => 1],
            self::Gloves => ['wool' => 3, 'straw' => 2],
            self::Shoes => ['stone' => 3, 'wood' => 2],
            self::Hedge => ['wood' => 4, 'straw' => 2],
            self::Bell => ['stone' => 2, 'clay' => 3],
            self::Cloak => ['wool' => 4, 'dye' => 2],
            self::Tonic => ['dye' => 2, 'clay' => 2, 'eggs' => 2],
            self::Basket => ['eggs' => 3, 'straw' => 3],
            self::Banner => ['wool' => 3, 'dye' => 3, 'wood' => 2],
            self::Ribbon => ['dye' => 4, 'wool' => 2, 'eggs' => 2],
            self::Trowel => ['stone' => 4, 'wood' => 3, 'clay' => 2],
            self::Sheaf => ['straw' => 6, 'dye' => 3, 'eggs' => 3],
        };
    }

    /** Who a copy's power is for: its owner, the owner's faction, or nobody for looks. */
    public function for(): ?string
    {
        return match ($this) {
            self::Hat, self::Scarf, self::Sign => null,
            self::Gloves, self::Shoes, self::Cloak, self::Tonic, self::Ribbon => 'you',
            default => 'faction',
        };
    }

    public function hasPower(): bool
    {
        return $this->for() !== null;
    }

    /** What using a copy does, in a sentence. */
    public function power(): ?string
    {
        return match ($this) {
            self::Hat, self::Scarf, self::Sign => null,
            self::Gloves => 'Your next 5 gathers bring in half as much again.',
            self::Shoes => 'Your horseshoes count a third more in your next raid or defence.',
            self::Cloak => 'Your next crime is half as likely to be caught.',
            self::Tonic => 'Walk out of the pound straight away.',
            self::Ribbon => 'Double Rosettes for 3 hours.',
            self::Banner => "Your faction's raids hit 15% harder for 12 hours.",
            self::Hedge => 'The region you stand in defends 15% harder for 24 hours, if your faction holds it.',
            self::Basket => 'Everyone in your faction gathers a quarter more for 12 hours.',
            self::Bell => 'Criminals who target your faction are a quarter likelier to be caught for 24 hours.',
            self::Trowel => "Finishes your faction's building going up in the region you stand in.",
            self::Sheaf => 'The House gets one extra law change. Only the House can use it.',
        };
    }

    /** The Creativity level a maker needs before designing on this shape. */
    public function creativity(): int
    {
        return match ($this) {
            self::Cloak, self::Tonic, self::Basket, self::Banner => 2,
            self::Ribbon, self::Trowel, self::Sheaf => 3,
            default => 1,
        };
    }
}
