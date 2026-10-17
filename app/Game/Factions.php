<?php

namespace App\Game;

use App\Enums\EntryMode;
use App\Enums\FactionRole;
use App\Enums\Species;
use App\Models\Faction;
use App\Models\FactionJoinRequest;
use App\Models\Player;
use App\Models\Region;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Founding, joining and running factions. A faction sets its own rules for
 * joining: open or by approval, a minimum level, the species it takes, an
 * entry fee in Corn, and whether it takes players with a criminal record.
 */
final class Factions
{
    /**
     * @param  array{entry_mode?: string, min_level?: int, species?: array<int, string>|null, entry_fee?: int, accepts_criminals?: bool}  $rules
     *
     * @throws GameException
     */
    public function found(Player $player, string $name, string $colour, ?string $motto, array $rules = []): Faction
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $motto = $motto !== null ? trim($motto) : null;

        return DB::transaction(function () use ($player, $name, $colour, $motto, $rules) {
            $player = Players::lock($player);
            Players::ensureFree($player);

            if (! $player->isLoneWolf()) {
                throw new GameException('Leave your faction before founding a new one.');
            }

            $level = (int) config('game.levels.found_faction');

            if ($player->level() < $level) {
                throw new GameException("You can found a faction from level {$level}. Earn Rosettes by gathering, trading and catching edits.");
            }

            $cost = (int) config('game.faction.founding_corn');

            if ($player->corn < $cost) {
                throw new GameException("Founding a faction costs {$cost} Corn. You have {$player->corn}.");
            }

            if (mb_strlen($name) < 3 || mb_strlen($name) > 30) {
                throw new GameException("A faction's name needs 3 to 30 characters.");
            }

            if (Faction::query()->where('valley_id', $player->valley_id)->where('name', $name)->exists()) {
                throw new GameException("There is already a faction called {$name}.");
            }

            if (! in_array($colour, Faction::COLOURS, true)) {
                throw new GameException('Pick one of the faction colours.');
            }

            if ($motto !== null && mb_strlen($motto) > 60) {
                throw new GameException('Keep the motto to 60 characters.');
            }

            $faction = Faction::create([
                'valley_id' => $player->valley_id,
                'name' => $name,
                'colour' => $colour,
                'motto' => $motto ?: null,
                ...$this->validRules($rules),
            ]);

            $player->corn -= $cost;
            $this->enrol($player, $faction, FactionRole::Leader);

            return $faction;
        });
    }

    /**
     * Join an open faction, or ask to join one that approves its members.
     *
     * @return string joined or requested
     *
     * @throws GameException
     */
    public function join(Player $player, Faction $faction): string
    {
        return DB::transaction(function () use ($player, $faction) {
            $player = Players::lock($player);
            $faction = Faction::query()->lockForUpdate()->findOrFail($faction->id);
            $this->checkEntry($player, $faction);

            if ($faction->entry_mode !== EntryMode::Open) {
                FactionJoinRequest::query()->updateOrCreate(
                    ['faction_id' => $faction->id, 'player_id' => $player->id],
                    ['status' => 'pending'],
                );

                return 'requested';
            }

            $this->admit($player, $faction);

            return 'joined';
        });
    }

    /**
     * @throws GameException
     */
    public function answer(Player $decider, FactionJoinRequest $request, bool $accept): void
    {
        DB::transaction(function () use ($decider, $request, $accept) {
            $request = FactionJoinRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($decider->faction_id !== $request->faction_id || ! $decider->decides()) {
                throw new GameException("Only the faction's leader or council can answer requests.");
            }

            if ($request->status !== 'pending') {
                throw new GameException('That request has already been answered.');
            }

            if (! $accept) {
                $request->update(['status' => 'declined']);

                return;
            }

            $player = Players::lock($request->player);
            $faction = Faction::query()->lockForUpdate()->findOrFail($request->faction_id);
            $this->checkEntry($player, $faction);
            $this->admit($player, $faction);
            $request->update(['status' => 'accepted']);
        });
    }

    /**
     * @throws GameException
     */
    public function leave(Player $player): void
    {
        DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            $faction = $player->faction;

            if ($faction === null) {
                throw new GameException("You aren't in a faction.");
            }

            $wasLeader = $player->faction_role === FactionRole::Leader;
            $player->forceFill(['faction_id' => null, 'faction_role' => null, 'faction_joined_at' => null])->save();

            $successor = $faction->members()
                ->orderByRaw("case faction_role when 'council' then 0 when 'member' then 1 else 2 end")
                ->orderBy('faction_joined_at')
                ->first();

            if ($successor === null) {
                // The last animal out: the faction's land goes back to the valley.
                Region::query()->where('faction_id', $faction->id)->update(['faction_id' => null, 'held_since' => null]);
                $faction->delete();

                return;
            }

            if ($wasLeader) {
                $successor->forceFill(['faction_role' => FactionRole::Leader])->save();
            }
        });
    }

    /**
     * @throws GameException
     */
    public function setRole(Player $leader, Player $member, FactionRole $role): void
    {
        DB::transaction(function () use ($leader, $member, $role) {
            $leader = Players::lock($leader);
            $member = Players::lock($member);

            if ($leader->faction_role !== FactionRole::Leader || $member->faction_id !== $leader->faction_id || $member->is($leader)) {
                throw new GameException('Only the leader can change other members\' roles.');
            }

            if ($role === FactionRole::Leader) {
                $leader->forceFill(['faction_role' => FactionRole::Council])->save();
            }

            if ($role === FactionRole::Council && $member->faction_role !== FactionRole::Council
                && Player::query()->where('faction_id', $leader->faction_id)->where('faction_role', FactionRole::Council)->count() >= 3) {
                throw new GameException('A faction can have up to 3 council members.');
            }

            $member->forceFill(['faction_role' => $role])->save();
        });
    }

    /**
     * @throws GameException
     */
    public function remove(Player $leader, Player $member): void
    {
        if (! $leader->decides() || $member->faction_id !== $leader->faction_id || $member->is($leader) || $member->faction_role === FactionRole::Leader) {
            throw new GameException("Only the leader or council can remove a member, and the leader can't be removed.");
        }

        $member->forceFill(['faction_id' => null, 'faction_role' => null, 'faction_joined_at' => null])->save();
    }

    /**
     * @param  array{entry_mode?: string, min_level?: int, species?: array<int, string>|null, entry_fee?: int, accepts_criminals?: bool}  $rules
     *
     * @throws GameException
     */
    public function setRules(Player $player, array $rules, ?string $motto): void
    {
        if (! $player->decides() || $player->faction === null) {
            throw new GameException("Only the faction's leader or council can change its rules.");
        }

        if ($motto !== null && mb_strlen(trim($motto)) > 60) {
            throw new GameException('Keep the motto to 60 characters.');
        }

        $player->faction->update([...$this->validRules($rules), 'motto' => $motto !== null ? (trim($motto) ?: null) : $player->faction->motto]);
    }

    /** Recruits get their vote after 48 hours. */
    public function promoteRecruits(): void
    {
        Player::query()
            ->where('faction_role', FactionRole::Recruit)
            ->where('faction_joined_at', '<=', CarbonImmutable::now()->subHours(48))
            ->update(['faction_role' => FactionRole::Member]);
    }

    /**
     * @throws GameException
     */
    private function checkEntry(Player $player, Faction $faction): void
    {
        Players::ensureFree($player);

        if (! $player->isLoneWolf()) {
            throw new GameException('Leave your faction before joining another.');
        }

        if ($faction->valley_id !== $player->valley_id) {
            throw new GameException('That faction is in another valley.');
        }

        if ($faction->members()->count() >= (int) config('game.faction.max_members')) {
            throw new GameException("{$faction->name} is full.");
        }

        if ($player->level() < $faction->min_level) {
            throw new GameException("{$faction->name} takes animals from level {$faction->min_level}.");
        }

        if ($faction->species !== null && $faction->species !== [] && ! in_array($player->species->value, $faction->species, true)) {
            throw new GameException("{$faction->name} doesn't take {$player->species->plural()}.");
        }

        if (! $faction->accepts_criminals && $player->wanted > 0) {
            throw new GameException("{$faction->name} doesn't take animals with a criminal record.");
        }

        if ($player->corn < $faction->entry_fee) {
            throw new GameException("Joining {$faction->name} costs {$faction->entry_fee} Corn. You have {$player->corn}.");
        }
    }

    private function admit(Player $player, Faction $faction): void
    {
        if ($faction->entry_fee > 0) {
            $player->corn -= $faction->entry_fee;
            $faction->increment('treasury', $faction->entry_fee);
        }

        $this->enrol($player, $faction, FactionRole::Recruit);
        FactionJoinRequest::query()->where('player_id', $player->id)->where('status', 'pending')->update(['status' => 'withdrawn']);
    }

    private function enrol(Player $player, Faction $faction, FactionRole $role): void
    {
        $player->forceFill([
            'faction_id' => $faction->id,
            'faction_role' => $role,
            'faction_joined_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * @param  array{entry_mode?: string, min_level?: int, species?: array<int, string>|null, entry_fee?: int, accepts_criminals?: bool}  $rules
     * @return array<string, mixed>
     */
    private function validRules(array $rules): array
    {
        $species = array_values(array_filter(
            (array) ($rules['species'] ?? []),
            fn ($value) => Species::tryFrom((string) $value) !== null,
        ));

        return [
            'entry_mode' => EntryMode::tryFrom((string) ($rules['entry_mode'] ?? '')) ?? EntryMode::Open,
            'min_level' => max(1, min(40, (int) ($rules['min_level'] ?? 1))),
            'species' => $species === [] ? null : $species,
            'entry_fee' => max(0, min(1000, (int) ($rules['entry_fee'] ?? 0))),
            'accepts_criminals' => (bool) ($rules['accepts_criminals'] ?? true),
        ];
    }
}
