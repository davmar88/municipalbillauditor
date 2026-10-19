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

    public const RARE = ['honey', 'herbs', 'iron', 'gold', 'copper', 'pears', 'carrots', 'berries', 'pumpkins'];

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
        'bram' => [
            'name' => 'Bram the smith',
            'role' => 'Works stone and wood into iron',
            'district' => 'irongate_smithy',
            'spot' => [40, 30],
            'says' => 'Bring me stone for the furnace and wood for the fire, and I will give you iron.',
        ],
        'nell' => [
            'name' => 'Nell',
            'role' => 'Keeps the Black Dog',
            'district' => 'irongate_tavern',
            'spot' => [40, 30],
            'says' => 'Sit down, eat something hot. You look like you walked here.',
        ],
        'cobb' => [
            'name' => 'Sergeant Cobb',
            'role' => "Drills Ironfield's men",
            'district' => 'irongate_gallows',
            'spot' => [60, 30],
            'says' => 'Left, right, left. On Sunday they march through your gate, and they march well.',
        ],
        'rook' => [
            'name' => 'Mother Rook',
            'role' => 'Tells fortunes',
            'district' => 'irongate_gallows',
            'spot' => [-50, 40],
            'says' => 'I see a farm changing hands, and a fox where a pig should be. Cross my palm with Corn some other day.',
        ],
    ];

    /** What Bram takes for one iron, and how often he'll work for you in a day. */
    public const SMITH_STONE = 10;

    public const SMITH_WOOD = 10;

    public const SMITH_DAILY = 3;

    /** What Nell's hot meal costs; it gives a horseshoe, once a day. */
    public const MEAL_CORN = 30;

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
     * Bram works stone and wood into iron, for anyone standing at Smithy Row,
     * a few times a day.
     *
     * @throws GameException
     */
    public function smith(Player $player): void
    {
        DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            Movement::ensureStandingIn($player, $player->valley->region('irongate_smithy'), 'deal with Bram');

            if ($this->dealsToday($player, 'smith') >= self::SMITH_DAILY) {
                throw new GameException('Bram has worked for you '.self::SMITH_DAILY.' times today. The furnace needs to cool.');
            }

            $materials = $player->materials ?? [];

            if ((int) ($materials['stone'] ?? 0) < self::SMITH_STONE || (int) ($materials['wood'] ?? 0) < self::SMITH_WOOD) {
                throw new GameException('Bram needs '.self::SMITH_STONE.' stone and '.self::SMITH_WOOD.' wood for a piece of iron.');
            }

            $materials['stone'] -= self::SMITH_STONE;
            $materials['wood'] -= self::SMITH_WOOD;
            $materials['iron'] = (int) ($materials['iron'] ?? 0) + 1;
            $player->forceFill(['materials' => $materials])->save();
            IrongateDeal::create(['player_id' => $player->id, 'kind' => 'smith', 'material' => 'iron', 'amount' => 1]);
        });
    }

    /**
     * Nell's hot meal: Corn for a horseshoe, once a day, at the Black Dog.
     *
     * @throws GameException
     */
    public function meal(Player $player): void
    {
        DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            Movement::ensureStandingIn($player, $player->valley->region('irongate_tavern'), 'eat at the Black Dog');

            if ($this->dealsToday($player, 'meal') > 0) {
                throw new GameException('You have eaten at the Black Dog today. Nell serves one hot meal a day.');
            }

            if (Horseshoes::current($player) >= Horseshoes::max()) {
                throw new GameException("You're full of horseshoes. Come and eat when you're short.");
            }

            if ($player->corn < self::MEAL_CORN) {
                throw new GameException('A hot meal is '.self::MEAL_CORN." Corn. You have {$player->corn}.");
            }

            $player->corn -= self::MEAL_CORN;
            Horseshoes::give($player, 1);
            IrongateDeal::create(['player_id' => $player->id, 'kind' => 'meal', 'corn' => self::MEAL_CORN]);
        });
    }

    private function dealsToday(Player $player, string $kind): int
    {
        return IrongateDeal::query()
            ->where('player_id', $player->id)
            ->where('kind', $kind)
            ->where('created_at', '>=', Clock::startOfToday())
            ->count();
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
