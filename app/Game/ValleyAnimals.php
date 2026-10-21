<?php

namespace App\Game;

use App\Enums\FactionRole;
use App\Enums\Species;
use App\Models\Faction;
use App\Models\Player;
use App\Models\Region;
use App\Models\User;
use App\Models\Valley;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Animals the game runs itself, so the valley is never empty: they walk the
 * map, gather, pitch tents and chat now and then. They are marked as valley
 * animals wherever players see them, and left out of the public counts and
 * the rankings. Nobody can sign in as one.
 */
final class ValleyAnimals
{
    /** Names for valley animals, picked in turn. */
    public const NAMES = [
        'Clover', 'Bramble', 'Pippin', 'Hazel', 'Thistle', 'Moss', 'Barley', 'Juniper', 'Sorrel', 'Fennel',
        'Willow', 'Nettle', 'Rowan', 'Tansy', 'Bracken', 'Poppy', 'Sage', 'Burdock', 'Hawthorn', 'Mallow',
        'Teasel', 'Yarrow', 'Linden', 'Cress', 'Dandelion', 'Heather', 'Larch', 'Briar', 'Chicory', 'Woodruff',
    ];

    /** What a valley animal says in the valley chat, now and then. */
    public const LINES = [
        'Anyone else think the Wall changed overnight?',
        'Fields by the Granary are full again.',
        'Watch out, I saw a fox near the Henhouse.',
        'Who rules the Farmhouse now? Their laws are getting heavy.',
        'The Duck Pond has water if you need some.',
        'Trading eggs for wood, anyone?',
        'Long day gathering. My hooves are sore.',
        'Irongate riders were spotted on the road again.',
        'Good morning, valley!',
        'Has anyone seen the Night Watch lately?',
        'The hens are loud today.',
        'Building my tent near the orchard. Nice spot.',
        'Somebody robbed me last night. Keep your Corn close.',
        'Winter is coming, stock up on food and water.',
        'All animals are equal. Some just have more Corn.',
    ];

    /** Factions valley animals found for themselves, with their mottos. */
    public const FACTIONS = [
        'Thistle Hollow' => 'Every seed counts.',
        'Bramble Band' => 'Thorns for thieves.',
        'Mossy Brook' => 'Slow water, deep pockets.',
        'Juniper Ridge' => 'We keep what we grow.',
    ];

    /** Whether the owner has the valley animals switched on (from the admin page). */
    public static function on(): bool
    {
        return app(Settings::class)->get('valley_animals_off') === null;
    }

    /**
     * Switch the valley animals on or off. Off, they stop, leave every faction
     * (factions of only valley animals break up and their land goes back to the
     * valley) and drop off the map, so real players have the valley to themselves.
     */
    public function switch(bool $on, ?User $by = null): void
    {
        app(Settings::class)->put('valley_animals_off', $on ? null : '1', $by);

        if ($on) {
            return;
        }

        Player::query()->where('npc', true)->whereNotNull('faction_id')->get()
            ->each(fn (Player $animal) => app(Factions::class)->leave($animal));
    }

    /** Some valley animals join a faction and fill its store with what they gather. Returns how many joined. */
    public function joinFaction(Faction $faction, int $count): int
    {
        $free = $this->free($faction->valley, $count);

        foreach ($free as $animal) {
            $animal->forceFill(['faction_id' => $faction->id, 'faction_role' => FactionRole::Member, 'faction_joined_at' => now()])->save();
        }

        if ($free->isNotEmpty()) {
            Notices::send(Notices::members($faction->id)->diff($free->pluck('id')), 'faction', '🌾 '.$free->count().' valley animals joined '.$faction->name.' and will fill its store with what they gather.', route('play.faction', [], false));
        }

        return $free->count();
    }

    /**
     * Valley animals found factions of their own: a leader and members each,
     * a store of Corn, and a farm of land nobody held. Returns the factions made.
     *
     * @return list<Faction>
     */
    public function foundFactions(Valley $valley, int $count, int $members): array
    {
        $made = [];
        $colours = array_values(array_diff(Faction::COLOURS, Faction::query()->where('valley_id', $valley->id)->pluck('colour')->all()));

        foreach (self::FACTIONS as $name => $motto) {
            if (count($made) >= $count) {
                break;
            }

            if (Faction::query()->where('valley_id', $valley->id)->where('name', $name)->exists()) {
                continue;
            }

            $animals = $this->free($valley, $members);

            if ($animals->isEmpty()) {
                break;
            }

            $faction = Faction::create([
                'valley_id' => $valley->id,
                'name' => $name,
                'colour' => $colours[count($made) % max(1, count($colours))] ?? Faction::COLOURS[0],
                'motto' => $motto,
            ]);
            // A store of Corn to start with: something to loot.
            $faction->forceFill(['treasury' => 300])->save();

            $animals->values()->each(fn (Player $animal, int $i) => $animal->forceFill([
                'faction_id' => $faction->id,
                'faction_role' => $i === 0 ? FactionRole::Leader : FactionRole::Member,
                'faction_joined_at' => now(),
            ])->save());

            // A farm of their own, from land nobody holds.
            $land = Region::query()
                ->where('valley_id', $valley->id)
                ->whereNull('faction_id')
                ->where('ring', 'farm')
                ->where('wild', false)
                ->inRandomOrder()
                ->first();
            $land?->forceFill(['faction_id' => $faction->id, 'held_since' => now()])->save();

            $made[] = $faction;
        }

        return $made;
    }

    /**
     * Valley animals in no faction, bringing more in when there aren't enough.
     *
     * @return Collection<int, Player>
     */
    private function free(Valley $valley, int $count): Collection
    {
        $free = fn () => Player::query()->where('valley_id', $valley->id)->where('npc', true)->whereNull('faction_id')->inRandomOrder()->limit($count)->get();
        $found = $free();

        if ($found->count() < $count) {
            $this->bring($valley, $count - $found->count());
            $found = $free();
        }

        return $found;
    }

    /** Bring valley animals into a valley, up to a number. Returns how many were added. */
    public function bring(Valley $valley, int $count): int
    {
        $species = Species::playable();
        $taken = Player::query()->where('valley_id', $valley->id)->pluck('name')->all();
        $added = 0;

        foreach (self::NAMES as $name) {
            if ($added >= $count) {
                break;
            }

            if (in_array($name, $taken, true)) {
                continue;
            }

            // An account nobody can sign in to: a random password nobody knows, an address that can't take mail.
            $user = User::query()->forceCreate([
                'name' => $name,
                'email' => 'valley-animal-'.Str::lower(Str::random(12)).'@npc.animalfarm.invalid',
                'password' => Hash::make(Str::random(64)),
                'email_verified_at' => now(),
            ]);

            $player = app(Players::class)->start($user, $valley, $name, $species[array_rand($species)], true);
            $player->forceFill(['npc' => true, 'news_emails' => false, 'corn' => 60, 'last_seen_at' => now()])->save();
            $added++;
        }

        return $added;
    }

    /** A minute of valley life: some of the valley animals do something. */
    public function live(int $valleyId): void
    {
        if (! self::on()) {
            return;
        }

        $now = CarbonImmutable::now();

        Player::query()
            ->where('valley_id', $valleyId)
            ->where('npc', true)
            ->where(fn ($query) => $query->whereNull('walk_arrives_at')->orWhere('walk_arrives_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('pound_until')->orWhere('pound_until', '<=', $now))
            ->inRandomOrder()
            ->limit(6)
            ->get()
            ->each(function (Player $animal) use ($now) {
                // Fed, watered and rested by the game; online, so the valley looks alive.
                $animal->forceFill([
                    'food' => 90, 'drink' => 90, 'health' => 100, 'vitals_at' => $now,
                    'last_seen_at' => $now, 'horseshoes' => Horseshoes::max(), 'horseshoes_at' => $now,
                ])->save();

                try {
                    $this->act($animal->fresh());
                } catch (Throwable) {
                    // A valley animal that can't do something just tries something else next time.
                }
            });
    }

    private function act(Player $animal): void
    {
        $roll = random_int(1, 100);
        $here = $animal->location();

        // In a faction: what it carries goes into the faction's store, now and then.
        $carried = (int) array_sum(array_map('intval', $animal->materials ?? []));
        if ($animal->faction_id !== null && ($animal->corn > 60 || $carried >= 15) && $roll <= 35) {
            $this->giveToStore($animal);

            return;
        }

        if ($roll <= 40 && $here->produces !== null && ! $here->isSafe()) {
            app(Gathering::class)->gather($animal, $here);

            return;
        }

        if ($roll <= 50 && Homes::of($animal) === null) {
            app(Homes::class)->build($animal, 0);

            return;
        }

        if ($roll <= 54 && Cache::add("valley-animals:chat:{$animal->valley_id}", true, now()->addMinutes(25))) {
            app(Chat::class)->post($animal, Chat::valley(), self::LINES[array_rand(self::LINES)]);

            return;
        }

        // Off somewhere else: a spot in a region that grows something.
        $region = Region::query()
            ->where('valley_id', $animal->valley_id)
            ->whereNotNull('produces')
            ->where('wild', false)
            ->inRandomOrder()
            ->first();

        if ($region !== null) {
            [$x, $y] = Geography::hub($region->key);
            app(Movement::class)->walkTo($animal, $x + random_int(-40, 40), $y + random_int(-30, 30));
        }
    }

    /** Hand what it gathered to its faction's store, keeping a little Corn to live on. */
    private function giveToStore(Player $animal): void
    {
        DB::transaction(function () use ($animal) {
            $animal = Players::lock($animal);
            $faction = Faction::query()->lockForUpdate()->find($animal->faction_id);

            if ($faction === null) {
                return;
            }

            $corn = max(0, $animal->corn - 20);
            $materials = array_filter(array_map('intval', $animal->materials ?? []));
            Stores::add($faction, $corn, $materials);
            $animal->forceFill(['corn' => $animal->corn - $corn, 'materials' => []])->save();
        });
    }
}
