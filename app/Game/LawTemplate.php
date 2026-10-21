<?php

namespace App\Game;

use App\Enums\Species;
use App\Models\Faction;

/**
 * The laws the House can paint on the Barn Wall, each with the limits the
 * server enforces and the unrest it adds while it stands.
 */
enum LawTemplate: string
{
    case GrainTax = 'grain_tax';
    case MarketLevy = 'market_levy';
    case Rations = 'rations';
    case Curfew = 'curfew';
    case TradeBan = 'trade_ban';
    case NightWatch = 'night_watch';
    case HomeTax = 'home_tax';
    case HarvestBonus = 'harvest_bonus';
    case CrimeFine = 'crime_fine';
    case ArrestReward = 'arrest_reward';
    case ArmsBan = 'arms_ban';
    case IrongatePact = 'irongate_pact';

    public function label(): string
    {
        return match ($this) {
            self::GrainTax => 'Grain Tax',
            self::MarketLevy => 'Market Levy',
            self::Rations => 'Rations',
            self::Curfew => 'Curfew',
            self::TradeBan => 'Trade Ban',
            self::NightWatch => 'Night Watch',
            self::HomeTax => 'Home Tax',
            self::HarvestBonus => 'Harvest Bonus',
            self::CrimeFine => 'Crime Fine',
            self::ArrestReward => 'Arrest Reward',
            self::ArmsBan => 'Arms Ban',
            self::IrongatePact => 'Irongate Pact',
        };
    }

    public function explains(): string
    {
        return match ($this) {
            self::GrainTax => 'A share of all Corn gathered goes to the House treasury.',
            self::MarketLevy => 'Buyers pay a Corn fee on every sale priced in Corn, into the House treasury.',
            self::Rations => 'Every morning the House hands back part of its grain tax to everyone. Without this law it keeps it all, which angers the valley.',
            self::Curfew => 'No raids on House land for some hours after 22:00.',
            self::TradeBan => 'One named faction may not buy or sell in the market, for 72 hours.',
            self::NightWatch => 'Raises the chance of catching criminals by 25%. Costs the treasury 50 Corn every half hour.',
            self::HomeTax => 'Every morning each animal with a home pays this much Corn to the House treasury. The House itself pays nothing.',
            self::HarvestBonus => 'The House treasury adds this share on top of all Corn gathered, for as long as it can pay.',
            self::CrimeFine => 'A criminal who is caught pays this much Corn to the House treasury, on top of paying back what they took.',
            self::ArrestReward => 'Whoever catches a criminal gets this much Corn from the House treasury.',
            self::ArmsBan => 'No Pitchforks, Cudgels, Slings or Iron Swords may be taken up in the valley.',
            self::IrongatePact => "A corrupt deal with Irongate's men: when their plunderers sack a farm, the House gets this share of the loot. The valley won't like it.",
        };
    }

    /** @return array{0: int, 1: int}|null The value's limits, or null when the law takes no number. */
    public function range(): ?array
    {
        return match ($this) {
            self::GrainTax => [0, 15],
            self::MarketLevy => [0, 5],
            self::Rations => [30, 100],
            self::Curfew => [1, 8],
            self::HomeTax => [1, 30],
            self::HarvestBonus => [1, 20],
            self::CrimeFine => [10, 200],
            self::ArrestReward => [10, 100],
            self::IrongatePact => [10, 50],
            self::TradeBan, self::NightWatch, self::ArmsBan => null,
        };
    }

    public function unit(): ?string
    {
        return match ($this) {
            self::GrainTax, self::MarketLevy, self::Rations, self::HarvestBonus, self::IrongatePact => '%',
            self::Curfew => 'hours',
            self::HomeTax, self::CrimeFine, self::ArrestReward => 'Corn',
            default => null,
        };
    }

    /** Trade Ban names a faction; Rations may favour a species. */
    public function target(): ?string
    {
        return match ($this) {
            self::TradeBan => 'faction',
            self::Rations => 'species',
            default => null,
        };
    }

    public function targetRequired(): bool
    {
        return $this === self::TradeBan;
    }

    public function defaultValue(): ?int
    {
        return match ($this) {
            self::GrainTax => 5,
            self::MarketLevy => 2,
            self::Rations => 50,
            self::Curfew => 4,
            self::HomeTax => 10,
            self::HarvestBonus => 5,
            self::CrimeFine => 50,
            self::ArrestReward => 25,
            self::IrongatePact => 25,
            default => null,
        };
    }

    public function unrest(?int $value, ?string $target): int
    {
        return match ($this) {
            self::GrainTax => (int) $value,
            self::MarketLevy => 2 * (int) $value,
            self::Rations => ((int) $value < 50 ? 5 : 0) + ($target !== null ? 5 : 0),
            self::Curfew => 4,
            self::TradeBan => 5,
            self::NightWatch => 2,
            // Taxing homes angers everyone; the rest are popular or cost the valley little.
            self::HomeTax => (int) ceil((int) $value / 3),
            self::HarvestBonus => 0,
            self::CrimeFine => 1,
            self::ArrestReward => 0,
            self::ArmsBan => 3,
            // Selling out the valley to the humans angers everyone.
            self::IrongatePact => 8 + intdiv((int) $value, 5),
        };
    }

    public function wording(?int $value, ?string $target): string
    {
        return match ($this) {
            self::GrainTax => "Every animal gives {$value}% of the Corn it gathers to the Farmhouse.",
            self::MarketLevy => "Every sale in the market pays {$value}% in Corn to the Farmhouse.",
            self::Rations => "The Farmhouse hands back {$value}% of its grain tax as rations every morning".
                (($species = Species::tryFrom((string) $target)) ? ", and the {$species->plural()} get a fifth more." : '.'),
            self::Curfew => "No raids on House land for {$value} hours after 22:00.",
            self::TradeBan => (Faction::query()->whereKey($target)->value('name') ?? 'That faction').' may not trade in the market.',
            self::NightWatch => 'The Night Watch patrols the valley after dark.',
            self::HomeTax => "Every animal with a home pays {$value} Corn to the Farmhouse each morning.",
            self::HarvestBonus => "The Farmhouse adds {$value}% to all Corn gathered.",
            self::CrimeFine => "A criminal who is caught pays a fine of {$value} Corn to the Farmhouse.",
            self::ArrestReward => "The Farmhouse pays {$value} Corn to whoever catches a criminal.",
            self::ArmsBan => 'No animal may take up arms in the valley.',
            self::IrongatePact => "The Farmhouse takes {$value}% of whatever Irongate's plunderers carry off.",
        };
    }
}
