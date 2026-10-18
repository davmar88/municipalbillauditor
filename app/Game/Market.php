<?php

namespace App\Game;

use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Models\Faction;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Listing;
use App\Models\Player;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Workshop and the shops. Players design items, make numbered copies
 * from materials, and sell them from their own shop for Corn. The House's
 * market levy is paid on every sale, and a maker earns a royalty when
 * their design is resold. Real money comes in a later stage.
 */
final class Market
{
    public const SHELVES = 6;

    public const MIN_PRICE = 10;

    public const MAX_PRICE = 5000;

    public const ROYALTY = 0.05;

    public const APPROVED_BEFORE_TRUSTED = 3;

    /**
     * @param  array<int, string>  $colours
     *
     * @throws GameException
     */
    public function design(Player $player, ItemTemplate $template, string $name, array $colours, string $pattern, ?int $edition): ItemDesign
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 30) {
            throw new GameException("An item's name needs 2 to 30 characters.");
        }

        $colours = array_values($colours);

        if (count($colours) !== 2 || array_diff($colours, ItemDesign::PALETTE) !== []) {
            throw new GameException('Pick two colours from the Workshop palette.');
        }

        if (! in_array($pattern, ItemDesign::PATTERNS, true)) {
            throw new GameException('Pick a pattern from the list.');
        }

        if ($edition !== null && ! in_array($edition, ItemDesign::EDITIONS, true)) {
            throw new GameException('Make 10, 50 or 250 copies, or an open edition.');
        }

        // Recipes with stronger powers open up as a maker's Creativity grows.
        if (Skills::level((int) $player->creativity_skill) < $template->creativity()) {
            throw new GameException("You can design {$template->label()} from Creativity level {$template->creativity()}. Design, make and sell to grow it.");
        }

        // A new maker's first designs are checked by a person before sale.
        $trusted = $player->designs()->where('status', 'approved')->count() >= self::APPROVED_BEFORE_TRUSTED;

        $design = ItemDesign::create([
            'player_id' => $player->id,
            'template' => $template,
            'name' => $name,
            'colours' => $colours,
            'pattern' => $pattern,
            'edition' => $edition,
            'status' => $trusted ? 'approved' : 'pending',
        ]);

        Missions::record($player, 'design');

        if ($trusted) {
            self::approved($design);
        }

        return $design;
    }

    /**
     * Make one numbered copy. Horses use half the materials.
     *
     * @throws GameException
     */
    public function craft(Player $player, ItemDesign $design): Item
    {
        if ($design->player_id !== $player->id) {
            throw new GameException('You can only make copies of your own designs.');
        }

        if ($design->status !== 'approved') {
            throw new GameException($design->status === 'pending'
                ? 'This design is waiting for a quick check before copies can be made.'
                : "This design wasn't approved.");
        }

        return DB::transaction(function () use ($player, $design) {
            $design = ItemDesign::query()->lockForUpdate()->findOrFail($design->id);
            $player = Players::lock($player);
            Players::ensureFree($player);

            if ($design->soldOut()) {
                throw new GameException("All {$design->edition} copies have been made.");
            }

            $needs = $this->materials($player, $design->template);

            foreach ($needs as $material => $amount) {
                if ($player->material($material) < $amount) {
                    throw new GameException("A {$design->template->label()} needs {$amount} {$material}. You have {$player->material($material)}. Gather some on the map.");
                }
            }

            Horseshoes::spend($player, 1);

            $materials = $player->materials;

            foreach ($needs as $material => $amount) {
                $materials[$material] -= $amount;
            }

            $player->materials = $materials;
            $player->save();

            $design->increment('made');
            $item = Item::create(['item_design_id' => $design->id, 'player_id' => $player->id, 'serial' => $design->made]);
            Missions::record($player, 'craft');
            Skills::award($player->id, Skills::CREATIVITY, 1, "Made a copy of {$design->name}", "craft:{$item->id}", $player->valley->currentSeason());

            return $item;
        });
    }

    /** @return array<string, int> */
    public function materials(Player $player, ItemTemplate $template): array
    {
        return array_map(
            fn (int $amount) => $player->species === Species::Horse ? (int) ceil($amount / 2) : $amount,
            $template->materials(),
        );
    }

    /**
     * @throws GameException
     */
    public function list(Player $player, Item $item, int $price): Listing
    {
        $level = (int) config('game.levels.stall');

        if ($item->player_id !== $player->id || $item->used_at !== null) {
            throw new GameException("That item isn't yours to sell.");
        }

        if ($player->level() < $level) {
            throw new GameException("You get a shop at level {$level}.");
        }

        if ($player->homeIsClosed()) {
            throw new GameException('Your home burned down, so your shop is closed. Rebuild it or wait until it reopens.');
        }

        if ($price < self::MIN_PRICE || $price > self::MAX_PRICE) {
            throw new GameException('Price items between '.self::MIN_PRICE.' and '.number_format(self::MAX_PRICE).' Corn.');
        }

        if ($item->openListing()->exists()) {
            throw new GameException('That item is already on your shelves.');
        }

        if (Listing::query()->where('player_id', $player->id)->whereNull('sold_at')->count() >= self::SHELVES) {
            throw new GameException('Your shop has '.self::SHELVES.' shelves and they are full.');
        }

        $listing = Listing::create(['item_id' => $item->id, 'player_id' => $player->id, 'price' => $price]);
        Missions::record($player, 'list');

        return $listing;
    }

    /**
     * @throws GameException
     */
    public function unlist(Player $player, Listing $listing): void
    {
        if ($listing->player_id !== $player->id || $listing->sold_at !== null) {
            throw new GameException("That isn't on your shelves.");
        }

        $listing->delete();
    }

    /**
     * @return array{price: int, levy: int}
     *
     * @throws GameException
     */
    public function buy(Player $buyer, Listing $listing): array
    {
        return DB::transaction(function () use ($buyer, $listing) {
            $listing = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            $buyer = Players::lock($buyer);
            $seller = Players::lock($listing->seller);
            $season = $buyer->valley->currentSeason();

            if ($listing->sold_at !== null) {
                throw new GameException('Someone else bought that first.');
            }

            if ($seller->is($buyer)) {
                throw new GameException("That's your own item.");
            }

            if ($seller->homeIsClosed()) {
                throw new GameException("{$seller->name}'s shop is closed while their home is rebuilt.");
            }

            Players::ensureFree($buyer);
            $this->checkBan($season, $buyer, $seller);

            $levyLaw = $season ? Wall::law($season, LawTemplate::MarketLevy) : null;
            $house = $season?->valley->house();
            $levy = ($levyLaw !== null && $house !== null) ? (int) floor($listing->price * $levyLaw->value / 100) : 0;
            $total = $listing->price + $levy;

            if ($buyer->corn < $total) {
                throw new GameException("That costs {$total} Corn with the market levy. You have {$buyer->corn}.");
            }

            $item = Item::query()->with('design')->findOrFail($listing->item_id);
            $maker = $item->design->player_id;
            $royalty = $maker !== $seller->id ? (int) floor($listing->price * self::ROYALTY) : 0;

            $buyer->decrement('corn', $total);
            $seller->increment('corn', $listing->price - $royalty);

            if ($royalty > 0) {
                Player::query()->whereKey($maker)->increment('corn', $royalty);
            }

            if ($levy > 0) {
                Faction::query()->whereKey($house->id)->increment('treasury', $levy);
            }

            $item->forceFill(['player_id' => $buyer->id])->save();
            $listing->forceFill(['sold_at' => CarbonImmutable::now(), 'buyer_player_id' => $buyer->id, 'levy' => $levy])->save();

            if (Rosettes::earnedToday($seller, 'sale:') < 60) {
                Rosettes::award($seller, 3, "Sold {$item->design->name}", "sale:{$listing->id}", $season);
            }

            // Selling and buying make a trader; every copy of your design that sells makes a maker.
            Skills::award($seller->id, Skills::MARKET, self::marketPoints($listing->price), "Sold {$item->design->name}", "sold:{$listing->id}", $season);
            Skills::award($buyer->id, Skills::MARKET, 1, "Bought {$item->design->name}", "bought:{$listing->id}", $season);
            Skills::award($maker, Skills::CREATIVITY, 2 + intdiv($listing->price, 50), "A copy of {$item->design->name} sold", "made:{$listing->id}", $season);
            Missions::record($seller, 'sale');
            Missions::record($buyer, 'buy');

            return ['price' => $listing->price, 'levy' => $levy];
        });
    }

    /**
     * @throws GameException
     */
    public function moderate(ItemDesign $design, bool $approve): void
    {
        if ($design->status !== 'pending') {
            throw new GameException('That design has already been checked.');
        }

        $design->update(['status' => $approve ? 'approved' : 'rejected']);

        if ($approve) {
            self::approved($design);
        }
    }

    /** Market points for a sale: more for a bigger sale, up to 40. */
    public static function marketPoints(int $price): int
    {
        return min(40, 4 + intdiv($price, 25));
    }

    /** A design passed its check, or needed none: Creativity for its maker. */
    private static function approved(ItemDesign $design): void
    {
        Skills::award($design->player_id, Skills::CREATIVITY, 10, "{$design->name} was approved", "design:{$design->id}", $design->player->valley->currentSeason());
    }

    /**
     * @throws GameException
     */
    private function checkBan(?Season $season, Player $buyer, Player $seller): void
    {
        $ban = $season ? Wall::law($season, LawTemplate::TradeBan) : null;

        if ($ban === null) {
            return;
        }

        $banned = (int) $ban->target;

        if ($buyer->faction_id === $banned || $seller->faction_id === $banned) {
            throw new GameException('A Trade Ban on the Wall stops that sale.');
        }
    }
}
