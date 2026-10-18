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

    public function label(): string
    {
        return match ($this) {
            self::GrainTax => 'Grain Tax',
            self::MarketLevy => 'Market Levy',
            self::Rations => 'Rations',
            self::Curfew => 'Curfew',
            self::TradeBan => 'Trade Ban',
            self::NightWatch => 'Night Watch',
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
            self::TradeBan, self::NightWatch => null,
        };
    }

    public function unit(): ?string
    {
        return match ($this) {
            self::GrainTax, self::MarketLevy, self::Rations => '%',
            self::Curfew => 'hours',
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
        };
    }
}
