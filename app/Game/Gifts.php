<?php

namespace App\Game;

use App\Models\Gift;
use App\Models\Item;
use App\Models\Listing;
use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Giving an item from your bag to another animal, from the map or its
 * profile. Any unused item that isn't on your shop shelf can go, a few a
 * day, so gifts stay gifts and don't become a way round the market or the
 * cash-out hold.
 */
final class Gifts
{
    /** How many items one animal can give away in a day. */
    public const PER_DAY = 5;

    /** How many items one animal can be given in a day, so nobody gathers a heap from spare accounts. */
    public const RECEIVE_PER_DAY = 10;

    /** @return Collection<int, Item> what you could give right now, newest first */
    public static function giftable(Player $player): Collection
    {
        return $player->items()
            ->whereDoesntHave('openListing')
            ->with('design')
            ->latest('id')
            ->get();
    }

    /** How many more items you can give today. */
    public static function left(Player $player): int
    {
        return max(0, self::PER_DAY - self::countToday('from_player_id', $player));
    }

    private static function countToday(string $column, Player $player): int
    {
        return Gift::query()
            ->where($column, $player->id)
            ->where('created_at', '>=', Clock::startOfToday())
            ->count();
    }

    public function give(Player $from, Player $to, int $itemId): Item
    {
        if ($from->is($to)) {
            throw new GameException("You can't give yourself a present.");
        }

        if ($from->valley_id !== $to->valley_id) {
            throw new GameException("{$to->name} lives in another valley.");
        }

        return DB::transaction(function () use ($from, $to, $itemId) {
            // The giver first, then the item, the same order as using an item's power.
            $from = Players::lock($from);
            $to = $to->fresh();
            Players::ensureFree($from);

            if ($from->level() < (int) config('game.levels.stall')) {
                throw new GameException('You can give items from level '.config('game.levels.stall').'.');
            }

            if (($silenced = Players::silenced($from)) !== null) {
                throw new GameException($silenced);
            }

            if (Messages::blocks($to, $from)) {
                throw new GameException("{$to->name} isn't taking anything from you.");
            }

            if (Messages::blocks($from, $to)) {
                throw new GameException("You blocked {$to->name}. Unblock them to give them something.");
            }

            // A gift mustn't spring an animal from the pound.
            if ($to->inPound()) {
                throw new GameException("{$to->name} is in the pound until ".Clock::local($to->pound_until)->format('H:i').'. Give it something when it is out.');
            }

            $season = $from->valley->currentSeason();
            $ban = $season ? Wall::law($season, LawTemplate::TradeBan) : null;

            if ($ban !== null && in_array((int) $ban->target, [$from->faction_id, $to->faction_id], true)) {
                throw new GameException('A Trade Ban on the Wall stops gifts to and from that faction.');
            }

            $item = Item::query()->with('design')->lockForUpdate()->find($itemId);

            if ($item === null || $item->player_id !== $from->id) {
                throw new GameException("That item isn't in your bag.");
            }

            if ($item->used_at !== null) {
                throw new GameException("{$item->design->name} is used up.");
            }

            if ($item->openListing()->exists()) {
                throw new GameException("{$item->design->name} is on your shop shelf. Take it off the shelf first.");
            }

            // Bought with Apples lately: it waits like the Apples would, so it can't hop to another account and be sold for cash.
            $bought = Listing::query()
                ->where('item_id', $item->id)
                ->where('buyer_player_id', $from->id)
                ->where('currency', 'apples')
                ->where('sold_at', '>', now()->subDays((int) config('game.cashout.hold_days')))
                ->exists();

            if ($bought) {
                throw new GameException("You bought {$item->design->name} with Apples in the last ".config('game.cashout.hold_days').' days, so it can\'t be given away yet.');
            }

            if (self::left($from) === 0) {
                throw new GameException('You have given '.self::PER_DAY.' items today. You can give more tomorrow.');
            }

            if (self::countToday('to_player_id', $to) >= self::RECEIVE_PER_DAY) {
                throw new GameException("{$to->name} has been given ".self::RECEIVE_PER_DAY.' items today. Try again tomorrow.');
            }

            $item->forceFill(['player_id' => $to->id])->save();
            Gift::create(['item_id' => $item->id, 'from_player_id' => $from->id, 'to_player_id' => $to->id]);
            Notices::send($to->id, 'gift', "🎁 {$from->name} gave you {$item->design->name}. It's in your bag.", route('play.market', [], false));

            return $item;
        });
    }
}
