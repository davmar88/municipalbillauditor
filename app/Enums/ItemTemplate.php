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
    case Cake = 'cake';
    case Pickaxe = 'pickaxe';
    case Lock = 'lock';
    case Kit = 'kit';
    case Gate = 'gate';
    case Plough = 'plough';
    case Speedo = 'speedo';
    case Skin = 'skin';
    case Mortar = 'mortar';
    case Crown = 'crown';
    case Ring = 'ring';
    case Pie = 'pie';
    case Stew = 'stew';
    // Weapons: made by anyone, sold in the Market, used up in a fight.
    case Pitchfork = 'pitchfork';
    case Cudgel = 'cudgel';
    case Sling = 'sling';
    case Sword = 'sword';
    // Weapons a faction unlocks with its Technology study.
    case Spyglass = 'spyglass';
    case Crossbow = 'crossbow';
    case Ram = 'ram';

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
            self::Cake => 'Honey Cake',
            self::Pickaxe => 'Iron Pickaxe',
            self::Lock => 'Iron Lock',
            self::Kit => "Healer's Kit",
            self::Gate => 'Iron Gate',
            self::Plough => 'Iron Plough',
            self::Speedo => 'Speedo',
            self::Skin => 'Home skin',
            self::Mortar => "Builder's Mortar",
            self::Crown => 'Gold Crown',
            self::Ring => 'Gold Ring',
            self::Pie => 'Fruit Pie',
            self::Stew => 'Veggie Stew',
            self::Pitchfork => 'Pitchfork',
            self::Cudgel => 'Cudgel',
            self::Sling => 'Sling',
            self::Sword => 'Iron Sword',
            self::Spyglass => 'Spyglass',
            self::Crossbow => 'Crossbow',
            self::Ram => 'Battering Ram',
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
            // Recipes that need what only the farms beyond the fences grow.
            self::Cake => ['honey' => 2, 'eggs' => 2, 'straw' => 2],
            self::Pickaxe => ['iron' => 3, 'wood' => 2],
            self::Lock => ['iron' => 2, 'stone' => 2],
            self::Kit => ['herbs' => 3, 'wool' => 2, 'eggs' => 1],
            self::Gate => ['iron' => 4, 'stone' => 3, 'wood' => 2],
            self::Plough => ['iron' => 2, 'wood' => 3, 'honey' => 1],
            // A drink: eggs and straw shaken up in a clay cup.
            self::Speedo => ['eggs' => 2, 'straw' => 1, 'clay' => 1],
            // Paint and boards for the outside of a home.
            self::Skin => ['dye' => 3, 'wood' => 2, 'clay' => 1],
            self::Mortar => ['stone' => 4, 'clay' => 4, 'eggs' => 2],
            // From the third ring of farms: gold and copper, fruit and veg.
            self::Crown => ['gold' => 3, 'dye' => 1],
            self::Ring => ['gold' => 1, 'copper' => 2],
            self::Pie => ['pears' => 2, 'berries' => 2, 'eggs' => 1],
            self::Stew => ['carrots' => 3, 'pumpkins' => 2],
            // Weapons.
            self::Pitchfork => ['wood' => 3, 'iron' => 1],
            self::Cudgel => ['wood' => 3, 'stone' => 2],
            self::Sling => ['straw' => 2, 'wool' => 2, 'stone' => 1],
            self::Sword => ['iron' => 3, 'copper' => 1, 'wood' => 1],
            self::Spyglass => ['copper' => 2, 'clay' => 2, 'wood' => 1],
            self::Crossbow => ['wood' => 3, 'iron' => 2, 'wool' => 1],
            self::Ram => ['wood' => 6, 'iron' => 3, 'stone' => 2],
        };
    }

    /** Who a copy's power is for: its owner, the owner's faction, or nobody for looks. */
    public function for(): ?string
    {
        return match ($this) {
            self::Hat, self::Scarf, self::Sign, self::Skin, self::Crown, self::Ring => null,
            self::Gloves, self::Shoes, self::Cloak, self::Tonic, self::Ribbon, self::Cake, self::Pickaxe, self::Lock, self::Speedo, self::Mortar, self::Pie, self::Stew,
            self::Pitchfork, self::Cudgel, self::Sling, self::Sword, self::Spyglass, self::Crossbow, self::Ram => 'you',
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
            self::Hat, self::Scarf, self::Sign, self::Skin, self::Crown, self::Ring => null,
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
            self::Cake => 'Your Market and Creativity skills grow twice as fast for 6 hours.',
            self::Pickaxe => 'Your next 10 gathers of materials bring in twice as much.',
            self::Lock => 'Nobody can burn down your home for 3 days.',
            self::Kit => 'Everyone in your faction walks out of the pound.',
            self::Gate => "The region you stand in can't be raided for 6 hours, if your faction holds it. Not the Farmhouse.",
            self::Plough => "Your faction's farms pay double for 24 hours.",
            self::Speedo => 'You walk twice as fast for an hour.',
            self::Pie => 'Your next 5 gathers bring in twice as much.',
            self::Stew => 'You get 3 horseshoes straight away.',
            self::Mortar => "Your home goes up a level at once: you pay that level's materials, not its Corn, and don't wait.",
            self::Pitchfork => 'Your horseshoes count half as much again in your next 3 raids.',
            self::Cudgel => 'Your next robbery takes twice as much and is a quarter less likely to be caught.',
            self::Sling => "Your horseshoes count double in your next fight against Irongate's plunderers.",
            self::Sword => "Your horseshoes count double in your next 3 raids, defences or fights against Irongate's plunderers.",
            self::Spyglass => 'Spy on a faction from its page: see what its store holds, how strong it is and what weapons its members carry. Only your faction sees what you found.',
            self::Crossbow => 'Your horseshoes count two and a half times in your next 2 raids or defences.',
            self::Ram => "The next raid you lead breaks through the Den and Watchtower where it lands: their walls don't count.",
        };
    }

    /** The Technology level a maker's faction needs before it can design or make this, or 0. */
    public function technology(): int
    {
        return (int) (array_search($this->value, Study::WEAPONS, true) ?: 0);
    }

    public function isWeapon(): bool
    {
        return in_array($this, [self::Pitchfork, self::Cudgel, self::Sling, self::Sword, self::Spyglass, self::Crossbow, self::Ram], true);
    }

    /** A Spyglass is for spying, never for sale. */
    public function sellable(): bool
    {
        return $this !== self::Spyglass;
    }

    /** The Creativity level a maker needs before designing on this shape. */
    public function creativity(): int
    {
        return match ($this) {
            self::Cloak, self::Tonic, self::Basket, self::Banner, self::Cake, self::Pickaxe, self::Lock, self::Kit, self::Gate, self::Plough, self::Mortar, self::Pie, self::Stew, self::Ring, self::Sword, self::Crossbow, self::Ram => 2,
            self::Crown => 3,
            self::Ribbon, self::Trowel, self::Sheaf => 3,
            default => 1,
        };
    }
}
