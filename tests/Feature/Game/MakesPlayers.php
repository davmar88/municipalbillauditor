<?php

namespace Tests\Feature\Game;

use App\Enums\BuildingKind;
use App\Enums\FactionRole;
use App\Enums\Species;
use App\Game\Levels;
use App\Game\Players;
use App\Game\ValleyBuilder;
use App\Models\Building;
use App\Models\Faction;
use App\Models\Player;
use App\Models\Region;
use App\Models\Season;
use App\Models\User;
use App\Models\Valley;
use Carbon\CarbonImmutable;

/**
 * A valley and animals to play with.
 */
trait MakesPlayers
{
    protected Valley $valley;

    protected function makeValley(): Valley
    {
        return $this->valley = app(ValleyBuilder::class)->build();
    }

    protected function season(): Season
    {
        return $this->valley->currentSeason();
    }

    /** @param  array<string, mixed>  $attributes */
    protected function player(Species $species = Species::Pig, int $level = 1, array $attributes = []): Player
    {
        static $count = 0;
        $count++;

        $player = app(Players::class)->start(User::factory()->create(), $this->valley, 'Animal '.chr(64 + ($count % 26 ?: 26)).$this->letters($count), $species, true);
        $player->forceFill([
            'rosettes' => Levels::threshold($level),
            'shield_until' => null,
            'last_visit_on' => CarbonImmutable::now()->toDateString(),
            ...$attributes,
        ])->save();

        return $player->fresh();
    }

    protected function faction(string $name, Player $leader, Player ...$members): Faction
    {
        $faction = Faction::create([
            'valley_id' => $this->valley->id,
            'name' => $name,
            'colour' => Faction::COLOURS[Faction::query()->count() % count(Faction::COLOURS)],
        ]);

        $leader->forceFill(['faction_id' => $faction->id, 'faction_role' => FactionRole::Leader, 'faction_joined_at' => now()->subDays(3)])->save();

        foreach ($members as $member) {
            $member->forceFill(['faction_id' => $faction->id, 'faction_role' => FactionRole::Member, 'faction_joined_at' => now()->subDays(3)])->save();
        }

        return $faction->fresh();
    }

    protected function region(string $key): Region
    {
        return $this->valley->region($key);
    }

    protected function hold(Faction $faction, string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->region($key)->forceFill(['faction_id' => $faction->id, 'held_since' => now()->subDay()])->save();

            // Holding the Farmhouse here means ruling from it: its Head Office stands.
            if ($key === 'farmhouse') {
                Building::query()->where('region_id', $this->region($key)->id)->delete();
                Building::create([
                    'faction_id' => $faction->id,
                    'region_id' => $this->region($key)->id,
                    'kind' => BuildingKind::HeadOffice,
                    'level' => 1,
                    'ready_at' => now()->subDay(),
                    'crowned_at' => now()->subDay(),
                ]);
            }
        }
    }

    protected function stand(Player $player, string $key): void
    {
        $player->forceFill(['region_id' => $this->region($key)->id])->save();
    }

    private function letters(int $count): string
    {
        $out = '';

        do {
            $out .= chr(97 + $count % 26);
            $count = intdiv($count, 26);
        } while ($count > 0);

        return $out;
    }
}
