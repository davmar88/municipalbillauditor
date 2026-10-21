<?php

namespace App\Game;

use App\Enums\Species;
use App\Models\Player;
use App\Models\Region;
use App\Models\User;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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
}
