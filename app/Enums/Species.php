<?php

namespace App\Enums;

/**
 * A player's animal is a job with one talent and one weak spot. Every
 * species can lead a faction: who ends up on top is the players' choice.
 */
enum Species: string
{
    case Pig = 'pig';
    case Dog = 'dog';
    case Horse = 'horse';
    case Sheep = 'sheep';
    case Goat = 'goat';
    case Hen = 'hen';
    case Donkey = 'donkey';
    case Raven = 'raven';
    case Cow = 'cow';
    case Llama = 'llama';
    case Zebra = 'zebra';
    case Tortoise = 'tortoise';

    /** The species players can pick in the test season. */
    public static function playable(): array
    {
        return [self::Pig, self::Dog, self::Horse, self::Sheep, self::Goat, self::Cow, self::Llama, self::Zebra, self::Tortoise];
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function plural(): string
    {
        return match ($this) {
            self::Sheep => 'Sheep',
            default => $this->label().'s',
        };
    }

    public function role(): string
    {
        return match ($this) {
            self::Pig => 'Planner',
            self::Dog => 'Guard',
            self::Horse => 'Builder',
            self::Sheep => 'Chorus',
            self::Goat => 'Auditor',
            self::Hen => 'Producer',
            self::Donkey => 'Witness',
            self::Raven => 'Scout',
            self::Cow => 'Provider',
            self::Llama => 'Packer',
            self::Zebra => 'Runner',
            self::Tortoise => 'Fortress',
        };
    }

    public function talent(): string
    {
        return match ($this) {
            self::Pig => 'An attack you launch gets +10% for everyone in it.',
            self::Dog => 'Your defence counts 30% more.',
            self::Horse => 'You craft items with half the materials.',
            self::Sheep => 'Once a day you can chant your faction motto to calm the valley under your own rulers, or stir it up under rivals.',
            self::Goat => 'You spot a secret edit on the Wall after 6 hours instead of 72.',
            self::Hen => 'You lay a free egg for crafting every half hour.',
            self::Donkey => 'You save a copy of the Wall every day without asking.',
            self::Raven => 'You see how big an attack on a neighbouring region is.',
            self::Cow => 'You gather 25% more.',
            self::Llama => 'You carry twice as much water, and get thirsty more slowly.',
            self::Zebra => 'You walk 30% faster.',
            self::Tortoise => 'Your defence counts 40% more, and robbers get only half as much off you.',
        };
    }

    public function weakSpot(): string
    {
        return match ($this) {
            self::Pig => 'Your own attack counts 20% less.',
            self::Dog => 'You gather 25% less.',
            self::Horse => 'You gather 20% less.',
            self::Sheep => 'Your defence counts 10% less.',
            self::Goat => 'Your defence counts 20% less.',
            self::Hen => 'Your attack and defence count 20% less.',
            self::Donkey => 'Your attack counts 10% less.',
            self::Raven => 'Your attack counts 30% less.',
            self::Cow => 'Your attack counts 15% less.',
            self::Llama => 'Your defence counts 15% less.',
            self::Zebra => 'You gather 15% less.',
            self::Tortoise => 'You walk 40% slower.',
        };
    }

    public function attack(): float
    {
        return match ($this) {
            self::Pig, self::Hen => 0.8,
            self::Donkey => 0.9,
            self::Raven => 0.7,
            self::Cow => 0.85,
            default => 1.0,
        };
    }

    public function defence(): float
    {
        return match ($this) {
            self::Dog => 1.3,
            self::Sheep => 0.9,
            self::Goat, self::Hen => 0.8,
            self::Llama => 0.85,
            self::Tortoise => 1.4,
            default => 1.0,
        };
    }

    /** How fast it walks, against the others. */
    public function pace(): float
    {
        return match ($this) {
            self::Zebra => 1.3,
            self::Tortoise => 0.6,
            default => 1.0,
        };
    }

    public function gathering(): float
    {
        return match ($this) {
            self::Dog => 0.75,
            self::Horse => 0.8,
            self::Raven => 0.5,
            self::Cow => 1.25,
            self::Zebra => 0.85,
            default => 1.0,
        };
    }
}
