<?php

namespace App\Game;

use App\Enums\Species;
use App\Models\IrongateDeal;
use App\Models\Player;
use App\Models\Region;
use App\Models\Valley;
use App\Models\Warrant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Irongate, the humans' town past Ironfield Gate. Nobody holds it and
 * nothing grows there. Its people are a fixed cast, not players: the
 * Garrison's captain, whose men raid on Gate Night; the fence, who buys
 * materials from lone wolves; the informer, who sells hunters word of
 * where an outlaw hides; and the keeper of the Rookery, where outlaws and
 * lone wolves lie low. An outlaw standing in Irongate is hidden from anyone
 * outside its district, but can still be caught there.
 */
final class Irongate
{
    /** What a hunter pays the informer for word of an outlaw's district. */
    public const TIP_CORN = 25;

    /** One tip on an outlaw per hunter this often, the same as an outlaw's escape. */
    public const TIP_MINUTES = 30;

    /** The most Corn the fence pays one animal in a day. */
    public const FENCE_DAILY_CORN = 60;

    /** Corn a unit: common materials and the farms' rare crops. */
    public const FENCE_PRICE = 2;

    public const FENCE_RARE_PRICE = 5;

    public const RARE = ['honey', 'herbs', 'iron'];

    /**
     * The townsfolk: who they are, where they stand (a nudge from their
     * district's hub) and what they say.
     *
     * @var array<string, array{name: string, role: string, district: string, spot: array{0: int, 1: int}, says: string}>
     */
    public const FOLK = [
        'brannock' => [
            'name' => 'Captain Hask Brannock',
            'role' => 'Captain of the Garrison',
            'district' => 'irongate_garrison',
            'spot' => [70, 0],
            'says' => 'Sunday, seven sharp, my men go through that gate. Count them and weep.',
        ],
        'vetch' => [
            'name' => 'Ma Vetch',
            'role' => 'The fence',
            'district' => 'irongate_yards',
            'spot' => [50, -20],
            'says' => "Straw, stone, honey, it's all the same to me. No questions, small Corn.",
        ],
        'wick' => [
            'name' => 'Wick',
            'role' => 'The informer',
            'district' => 'irongate_yards',
            'spot' => [110, 50],
            'says' => 'Everybody hides somewhere. For a little Corn I remember where.',
        ],
        'tamsin' => [
            'name' => 'Old Tamsin',
            'role' => 'Keeps the Rookery',
            'district' => 'irongate_rookery',
            'spot' => [70, -20],
            'says' => 'Rooms for the ones nobody wants. Keep your head down and your board talk honest.',
        ],
        'fallow' => [
            'name' => 'Ned Fallow',
            'role' => 'The fixer',
            'district' => 'irongate_rookery',
            'spot' => [110, 50],
            'says' => "Lone wolves do better in a pack. Come back when there's more of you.",
        ],
    ];

    /**
     * Whether an animal standing at $where is hidden from a viewer standing
     * at $from: outlaws in Irongate show only to animals in the same
     * district, and to a Dog anywhere in the town, whose nose finds them.
     *
     * @param  list<int>  $wanted  ids of animals with an open warrant
     */
    public static function hidden(int $animalId, Region $where, Player $viewer, Region $from, array $wanted): bool
    {
        return $where->isCity()
            && $animalId !== $viewer->id
            && in_array($animalId, $wanted, true)
            && $from->id !== $where->id
            && ! ($viewer->species === Species::Dog && $from->isCity());
    }

    /** Ironfield's men mustering for Sunday, whatever day it is. */
    public static function muster(Valley $valley): int
    {
        return GateNight::humans($valley);
    }

    /** Corn the fence pays for one unit of a material. */
    public static function price(string $material): int
    {
        return in_array($material, self::RARE, true) ? self::FENCE_RARE_PRICE : self::FENCE_PRICE;
    }

    /** How much more Corn the fence will pay this animal today. */
    public static function fenceLeft(Player $player): int
    {
        $paid = (int) IrongateDeal::query()
            ->where('player_id', $player->id)
            ->where('kind', 'fence')
            ->where('created_at', '>=', Clock::startOfToday())
            ->sum('corn');

        return max(0, self::FENCE_DAILY_CORN - $paid);
    }

    /**
     * Sell materials to the fence for Corn: lone wolves only, standing in the
     * Iron Yards. She pays less than gathering would, so it only pays for
     * what was stolen. Returns how many were sold and what she paid.
     *
     * @return array{amount: int, corn: int}
     *
     * @throws GameException
     */
    public function fence(Player $player, string $material, int $amount): array
    {
        if (! in_array($material, [...Player::MATERIALS, ...self::RARE], true)) {
            throw new GameException("Ma Vetch doesn't deal in that.");
        }

        if ($amount < 1) {
            throw new GameException('Sell at least one.');
        }

        return DB::transaction(function () use ($player, $material, $amount) {
            $player = Players::lock($player);
            Players::ensureFree($player);

            if (! $player->isLoneWolf()) {
                throw new GameException("Ma Vetch won't deal with faction animals. Too many friends asking questions.");
            }

            $yards = $player->valley->region('irongate_yards');
            Movement::ensureStandingIn($player, $yards, 'deal with Ma Vetch');

            $materials = $player->materials ?? [];
            $have = (int) ($materials[$material] ?? 0);

            if ($have < 1) {
                throw new GameException("You have no {$material} to sell.");
            }

            $price = self::price($material);
            $left = self::fenceLeft($player);

            if ($left < $price) {
                throw new GameException('Ma Vetch has paid you all she will today ('.self::FENCE_DAILY_CORN.' Corn). Come back tomorrow.');
            }

            // As many as she'll pay for today, and no more than you have.
            $amount = min($amount, $have, intdiv($left, $price));
            $corn = $amount * $price;
            $materials[$material] = $have - $amount;
            $player->forceFill(['materials' => $materials, 'corn' => $player->corn + $corn])->save();
            IrongateDeal::create(['player_id' => $player->id, 'kind' => 'fence', 'material' => $material, 'amount' => $amount, 'corn' => $corn]);

            return ['amount' => $amount, 'corn' => $corn];
        });
    }

    /**
     * Pay the informer for word of where an outlaw hides in Irongate. The
     * outlaw hears that someone asked, not who. Returns the district's name.
     *
     * @throws GameException
     */
    public function tip(Player $hunter, Warrant $warrant): string
    {
        return DB::transaction(function () use ($hunter, $warrant) {
            // The same order as an arrest: the warrant, the hunter, then the outlaw, settled where it really is.
            $warrant = Warrant::query()->lockForUpdate()->findOrFail($warrant->id);
            $hunter = Players::lock($hunter);
            Players::ensureFree($hunter);
            $wanted = Players::lock($warrant->wanted);

            if ($warrant->status !== 'open' || $wanted->valley_id !== $hunter->valley_id) {
                throw new GameException('That warrant is closed.');
            }

            if ($warrant->player_id === $hunter->id) {
                throw new GameException('Wick laughs. "You know where you are, friend."');
            }

            if (! $hunter->location()->isCity()) {
                throw new GameException('Wick only talks in Irongate. Walk to the Iron Yards to find him.');
            }

            $where = $wanted->location();

            if (! $where->isCity()) {
                throw new GameException("Wick shrugs: {$wanted->name} isn't hiding in Irongate. The Wanted board shows where they were last seen.");
            }

            $asked = IrongateDeal::query()
                ->where('player_id', $hunter->id)
                ->where('kind', 'tip')
                ->where('warrant_id', $warrant->id)
                ->where('created_at', '>', CarbonImmutable::now()->subMinutes(self::TIP_MINUTES))
                ->exists();

            if ($asked) {
                throw new GameException("Wick told you about {$wanted->name} not long ago. Ask again in a while.");
            }

            if ($hunter->corn < self::TIP_CORN) {
                throw new GameException('Wick wants '.self::TIP_CORN." Corn. You have {$hunter->corn}.");
            }

            $hunter->decrement('corn', self::TIP_CORN);
            IrongateDeal::create(['player_id' => $hunter->id, 'kind' => 'tip', 'corn' => self::TIP_CORN, 'warrant_id' => $warrant->id]);
            Notices::send($wanted->id, 'warrant', '👂 Someone paid Wick to find out where you hide in Irongate.', route('play.wanted', [], false));

            return $where->name;
        });
    }
}
