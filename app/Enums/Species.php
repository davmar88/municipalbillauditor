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
    case CatSilver = 'cat_silver';
    case CatBlack = 'cat_black';
    case CatGinger = 'cat_ginger';
    case CatMarmalade = 'cat_marmalade';
    case CatTabby = 'cat_tabby';
    case CatWhite = 'cat_white';
    case CatMasked = 'cat_masked';
    case CatTuxedo = 'cat_tuxedo';
    case CatBlue = 'cat_blue';
    case CatChocolate = 'cat_chocolate';
    case CatSilverTabby = 'cat_silvertabby';
    case CatCalico = 'cat_calico';

    /** The twelve cats, one for each coat in the pack, each with its own knack. */
    private const CATS = [
        'cat_silver' => ['label' => 'Silver Cat', 'role' => 'Diplomat', 'talent' => 'Your defence counts 15% more.', 'weak' => 'You gather 10% less.', 'attack' => 1.0, 'defence' => 1.15, 'gathering' => 0.9, 'pace' => 1.0],
        'cat_black' => ['label' => 'Black Cat', 'role' => 'Shadow', 'talent' => "You're a fifth harder to catch after a crime.", 'weak' => 'Your attack counts 10% less.', 'attack' => 0.9, 'defence' => 1.0, 'gathering' => 1.0, 'pace' => 1.0],
        'cat_ginger' => ['label' => 'Ginger Cat', 'role' => 'Brawler', 'talent' => 'Your attack counts 20% more.', 'weak' => 'Your defence counts 15% less.', 'attack' => 1.2, 'defence' => 0.85, 'gathering' => 1.0, 'pace' => 1.0],
        'cat_marmalade' => ['label' => 'Marmalade Cat', 'role' => 'Forager', 'talent' => 'You gather 15% more.', 'weak' => 'Your attack counts 10% less.', 'attack' => 0.9, 'defence' => 1.0, 'gathering' => 1.15, 'pace' => 1.0],
        'cat_tabby' => ['label' => 'Tabby Cat', 'role' => 'Hunter', 'talent' => 'Your attack counts 10% more, and you walk 10% faster.', 'weak' => 'Your defence counts 10% less.', 'attack' => 1.1, 'defence' => 0.9, 'gathering' => 1.0, 'pace' => 1.1],
        'cat_white' => ['label' => 'White Cat', 'role' => 'Ghost', 'talent' => 'You walk 20% faster.', 'weak' => 'You gather 10% less.', 'attack' => 1.0, 'defence' => 1.0, 'gathering' => 0.9, 'pace' => 1.2],
        'cat_masked' => ['label' => 'Masked Cat', 'role' => 'Bandit', 'talent' => 'Your robberies take half as much again.', 'weak' => 'Your defence counts 15% less.', 'attack' => 1.0, 'defence' => 0.85, 'gathering' => 1.0, 'pace' => 1.0],
        'cat_tuxedo' => ['label' => 'Tuxedo Cat', 'role' => 'Gentleman', 'talent' => 'Your defence counts 20% more.', 'weak' => 'You walk 10% slower.', 'attack' => 1.0, 'defence' => 1.2, 'gathering' => 1.0, 'pace' => 0.9],
        'cat_blue' => ['label' => 'Blue Cat', 'role' => 'Stoic', 'talent' => 'You carry half as much water again, and get thirsty more slowly.', 'weak' => 'Your attack counts 10% less.', 'attack' => 0.9, 'defence' => 1.0, 'gathering' => 1.0, 'pace' => 1.0],
        'cat_chocolate' => ['label' => 'Chocolate Cat', 'role' => 'Homebody', 'talent' => 'Robbers get only half as much off you.', 'weak' => 'You gather 10% less.', 'attack' => 1.0, 'defence' => 1.0, 'gathering' => 0.9, 'pace' => 1.0],
        'cat_silvertabby' => ['label' => 'Silver Tabby', 'role' => 'Scout', 'talent' => 'You walk 25% faster.', 'weak' => 'Your defence counts 10% less.', 'attack' => 1.0, 'defence' => 0.9, 'gathering' => 1.0, 'pace' => 1.25],
        'cat_calico' => ['label' => 'Calico Cat', 'role' => 'Lucky', 'talent' => 'You gather 20% more.', 'weak' => 'Your attack counts 15% less.', 'attack' => 0.85, 'defence' => 1.0, 'gathering' => 1.2, 'pace' => 1.0],
    ];

    /** The species players can pick in the test season. */
    public static function playable(): array
    {
        return [self::Pig, self::Dog, self::Horse, self::Sheep, self::Goat, self::Cow, self::Llama, self::Zebra, self::Tortoise, ...array_map(fn (string $cat) => self::from($cat), array_keys(self::CATS))];
    }

    public function label(): string
    {
        return self::CATS[$this->value]['label'] ?? ucfirst($this->value);
    }

    public function isCat(): bool
    {
        return isset(self::CATS[$this->value]);
    }

    public function plural(): string
    {
        return match ($this) {
            self::Sheep => 'Sheep',
            self::CatSilverTabby => 'Silver Tabbies',
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
            default => self::CATS[$this->value]['role'],
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
            default => self::CATS[$this->value]['talent'],
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
            default => self::CATS[$this->value]['weak'],
        };
    }

    public function attack(): float
    {
        return match ($this) {
            self::Pig, self::Hen => 0.8,
            self::Donkey => 0.9,
            self::Raven => 0.7,
            self::Cow => 0.85,
            default => self::CATS[$this->value]['attack'] ?? 1.0,
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
            default => self::CATS[$this->value]['defence'] ?? 1.0,
        };
    }

    /** How fast it walks, against the others. */
    public function pace(): float
    {
        return match ($this) {
            self::Zebra => 1.3,
            self::Tortoise => 0.6,
            default => self::CATS[$this->value]['pace'] ?? 1.0,
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
            default => self::CATS[$this->value]['gathering'] ?? 1.0,
        };
    }
}
