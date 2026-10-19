<?php

namespace App\Game;

use App\Models\Gift;
use App\Models\Item;
use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Giving an item from your bag to another animal, from the map or its
 * profile. Any unused item that isn't on your shop shelf can go, a few a
 * day, so gifts stay gifts and don't become a way round the market.
 */
final class Gifts
{
    /** How many items one animal can give away in a day. */
    public const PER_DAY = 5;

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
        $given = Gift::query()
            ->where('from_player_id', $player->id)
            ->where('created_at', '>=', Clock::startOfToday())
            ->count();

        return max(0, self::PER_DAY - $given);
    }

    public function give(Player $from, Player $to, int $itemId): Item
    {
        if ($from->is($to)) {
            throw new GameException("You can't give yourself a present.");
        }

        if ($from->valley_id !== $to->valley_id) {
            throw new GameException("{$to->name} lives in another valley.");
        }

        if (Messages::blocks($to, $from)) {
            throw new GameException("{$to->name} isn't taking anything from you.");
        }

        return DB::transaction(function () use ($from, $to, $itemId) {
            // Locked, so two quick taps can't give the same item twice.
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

            Player::query()->whereKey($from->id)->lockForUpdate()->first();

            if (self::left($from) === 0) {
                throw new GameException('You have given '.self::PER_DAY.' items today. You can give more tomorrow.');
            }

            $item->forceFill(['player_id' => $to->id])->save();
            Gift::create(['item_id' => $item->id, 'from_player_id' => $from->id, 'to_player_id' => $to->id]);
            Notices::send($to->id, 'gift', "🎁 {$from->name} gave you {$item->design->name}. It's in your bag.", route('play.market', [], false));

            return $item;
        });
    }
}
