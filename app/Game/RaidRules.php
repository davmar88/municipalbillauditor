<?php

namespace App\Game;

use App\Enums\CrimeKind;
use App\Models\Faction;
use App\Models\FactionStrike;
use App\Models\Player;
use App\Models\Region;
use Illuminate\Support\Collection;

/**
 * A faction's own raid rules. Any animal can raid any region from anywhere;
 * a faction's leader and council set the rules its members are held to. A
 * raid that breaks one still goes ahead, but earns its leader a strike, and
 * a member with several strikes is flagged to the faction. The leader can
 * excuse a member from the rules, or run the faction rogue with none.
 */
final class RaidRules
{
    /** The rules a faction can set, and what each asks of members. */
    public const RULES = [
        'front_line' => 'Raid only from the front line: stand next to the region you raid.',
        'near_land' => 'Raid only regions next to land we hold.',
        'leader_call' => 'Only the leader and council start raids.',
        'spare' => 'Leave the factions we name alone.',
        'mates' => 'Never burn, loot or plunder our own faction or its members.',
    ];

    /** Strikes this season that flag a member to the faction. */
    public const FLAG_AT = 3;

    /** @return array{rules: list<string>, spare: list<int>} */
    public static function of(Faction $faction): array
    {
        $set = $faction->raid_rules ?? [];

        return [
            'rules' => array_values(array_intersect(array_keys(self::RULES), $set['rules'] ?? [])),
            'spare' => array_values(array_map('intval', $set['spare'] ?? [])),
        ];
    }

    /**
     * The faction rules this raid would break, by key.
     *
     * @return list<string>
     */
    public static function broken(Player $player, Faction $faction, Region $target): array
    {
        if ($faction->rogue || $player->rules_exempt) {
            return [];
        }

        ['rules' => $rules, 'spare' => $spare] = self::of($faction);
        $broken = [];

        foreach ($rules as $rule) {
            $breaks = match ($rule) {
                'front_line' => ! $target->neighbours()->whereKey($player->location()->id)->exists() && $player->location()->id !== $target->id,
                'near_land' => ! $target->neighbours()->where('faction_id', $faction->id)->exists(),
                'leader_call' => ! $player->decides(),
                'spare' => $target->faction_id !== null && in_array($target->faction_id, $spare, true),
                default => false,
            };

            if ($breaks) {
                $broken[] = $rule;
            }
        }

        return $broken;
    }

    /** Give the raider a strike for each rule the raid breaks, and tell the leader and council. */
    public static function judge(Player $player, Faction $faction, Region $target): void
    {
        $broken = self::broken($player, $faction, $target);

        if ($broken === []) {
            return;
        }

        foreach ($broken as $rule) {
            FactionStrike::query()->create([
                'faction_id' => $faction->id,
                'player_id' => $player->id,
                'rule' => $rule,
                'detail' => "Raided {$target->name}",
            ]);
        }

        $strikes = self::strikes($faction)->get($player->id, 0);
        $flag = $strikes >= self::FLAG_AT ? " That's {$strikes} strikes: they're flagged." : '';
        Notices::send(
            Notices::deciders($faction->id)->reject(fn (int $id) => $id === $player->id),
            'faction',
            "⚑ {$player->name} broke your faction's raid rules raiding {$target->name}.{$flag}",
            route('play.faction', [], false),
        );
    }

    /** A member who burns, loots or plunders their own faction: a strike if the faction forbids it, and the leader hears either way. */
    public static function judgeCrime(Player $player, CrimeKind $kind, ?string $what): void
    {
        $faction = $player->faction;

        if ($faction === null) {
            return;
        }

        $forbidden = ! $faction->rogue && ! $player->rules_exempt && in_array('mates', self::of($faction)['rules'], true);

        if ($forbidden) {
            FactionStrike::query()->create([
                'faction_id' => $faction->id,
                'player_id' => $player->id,
                'rule' => 'mates',
                'detail' => self::deed($kind, $what),
            ]);
        }

        $strikes = self::strikes($faction)->get($player->id, 0);
        $flag = $forbidden && $strikes >= self::FLAG_AT ? " That's {$strikes} strikes: they're flagged." : '';
        Notices::send(
            Notices::deciders($faction->id)->reject(fn (int $id) => $id === $player->id),
            'faction',
            ($forbidden ? '⚑ ' : '🔥 ')."{$player->name} turned on their own faction: ".lcfirst(self::deed($kind, $what)).".{$flag}",
            route('play.faction', [], false),
        );
    }

    private static function deed(CrimeKind $kind, ?string $what): string
    {
        return match ($kind) {
            CrimeKind::Burn => "Set fire to {$what}'s home",
            CrimeKind::Loot => 'Looted the treasury',
            CrimeKind::Plunder => "Plundered {$what}",
            CrimeKind::Rob => "Robbed {$what}",
            CrimeKind::Attack => "Attacked {$what}",
        };
    }

    /**
     * Each member's strikes this season.
     *
     * @return Collection<int, int>
     */
    public static function strikes(Faction $faction): Collection
    {
        $since = $faction->valley->currentSeason()?->starts_at;

        return FactionStrike::query()
            ->where('faction_id', $faction->id)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->selectRaw('player_id, count(*) as total')
            ->groupBy('player_id')
            ->pluck('total', 'player_id')
            ->map(fn ($total) => (int) $total);
    }

    /**
     * Set the faction's raid rules: which rules, which factions to spare,
     * and whether it runs rogue with none.
     *
     * @param  list<string>  $rules
     * @param  list<int>  $spare
     *
     * @throws GameException
     */
    public function set(Player $player, array $rules, array $spare, bool $rogue): void
    {
        $faction = $player->faction ?? throw new GameException('Join a faction first.');

        if (! $player->decides()) {
            throw new GameException("Only your faction's leader and council set its raid rules.");
        }

        $faction->forceFill([
            'raid_rules' => [
                'rules' => array_values(array_intersect(array_keys(self::RULES), $rules)),
                'spare' => array_values(array_diff(array_map('intval', $spare), [$faction->id])),
            ],
            'rogue' => $rogue,
        ])->save();
    }

    /**
     * Excuse a member from the raid rules, or hold them to them again.
     *
     * @throws GameException
     */
    public function exempt(Player $leader, Player $member, bool $exempt): void
    {
        $this->ensureLeads($leader, $member);
        $member->forceFill(['rules_exempt' => $exempt])->save();
    }

    /**
     * Wipe a member's strikes.
     *
     * @throws GameException
     */
    public function forgive(Player $leader, Player $member): void
    {
        $this->ensureLeads($leader, $member);
        FactionStrike::query()->where('faction_id', $leader->faction_id)->where('player_id', $member->id)->delete();
    }

    /** @throws GameException */
    private function ensureLeads(Player $leader, Player $member): void
    {
        if ($leader->faction_id === null || $member->faction_id !== $leader->faction_id) {
            throw new GameException("{$member->name} isn't in your faction.");
        }

        if (! $leader->decides()) {
            throw new GameException("Only your faction's leader and council can do that.");
        }
    }
}
