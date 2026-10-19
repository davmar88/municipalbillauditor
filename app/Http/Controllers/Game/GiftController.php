<?php

namespace App\Http\Controllers\Game;

use App\Game\Gifts;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Giving another animal an item from your bag, from the map's animal menu. */
class GiftController extends Controller
{
    /** What you could give, and how many more you can give today. */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->bag($request->user()->player));
    }

    public function store(Request $request, Player $player, Gifts $gifts): JsonResponse
    {
        $data = $request->validate(['item' => ['required', 'integer']]);
        $me = $request->user()->player;
        $item = $gifts->give($me, $player, (int) $data['item']);

        return response()->json([
            ...$this->bag($me->fresh()),
            'message' => "You gave {$player->name} {$item->design->name}.",
        ]);
    }

    /** @return array{items: list<array<string, mixed>>, left: int, perDay: int, cant: string|null} */
    private function bag(Player $player): array
    {
        return [
            'items' => Gifts::giftable($player)->map(fn (Item $item) => [
                'id' => $item->id,
                'serial' => $item->serial,
                'name' => $item->design->name,
                'kind' => $item->design->template->label(),
                'colours' => $item->design->colours,
                'pattern' => $item->design->pattern,
            ])->values()->all(),
            'left' => Gifts::left($player),
            'perDay' => Gifts::PER_DAY,
            // Why you can't give anything just now, so the picker can say so up front.
            'cant' => match (true) {
                $player->level() < (int) config('game.levels.stall') => 'You can give items from level '.config('game.levels.stall').'.',
                $player->inPound() => "You can't give anything from the pound.",
                default => null,
            },
        ];
    }
}
