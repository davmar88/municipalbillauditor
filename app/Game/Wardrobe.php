<?php

namespace App\Game;

use App\Enums\ItemTemplate;
use App\Models\Item;
use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * What an animal wears on its profile and on the map: the shop items that go
 * on a body, one per spot. A copy is worn until its power is used up, and one
 * on a shop shelf isn't worn. With two of a kind, the newest copy is the one worn.
 */
final class Wardrobe
{
    /**
     * Each spot and the items that go there, the first owned one winning.
     *
     * @var array<string, list<ItemTemplate>>
     */
    public const SPOTS = [
        // A Gold Crown, when you have one, is worn instead of a hat.
        'hat' => [ItemTemplate::Crown, ItemTemplate::Hat],
        'scarf' => [ItemTemplate::Scarf],
        'bell' => [ItemTemplate::Bell],
        'cloak' => [ItemTemplate::Cloak],
        'ribbon' => [ItemTemplate::Ribbon],
        'gloves' => [ItemTemplate::Gloves],
        'shoes' => [ItemTemplate::Shoes],
        // Animals have no hands, so one tool or banner rides on the back.
        'carried' => [ItemTemplate::Banner, ItemTemplate::Pickaxe, ItemTemplate::Basket],
    ];

    /** @return list<array{spot: string, template: string, name: string, colours: array<int, string>, pattern: string}> */
    public static function worn(Player $player): array
    {
        return self::wornBy([$player->id])[$player->id] ?? [];
    }

    /**
     * What each of several animals wears, in one query: the map dresses every
     * animal it shows.
     *
     * @param  iterable<int>  $playerIds
     * @return array<int, list<array{spot: string, template: string, name: string, colours: array<int, string>, pattern: string}>>
     */
    public static function wornBy(iterable $playerIds): array
    {
        $ids = collect($playerIds)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $templates = array_merge(...array_values(self::SPOTS));
        $owned = Item::query()
            ->whereIn('player_id', $ids)
            ->whereNull('used_at')
            ->whereDoesntHave('openListing')
            ->whereHas('design', fn ($query) => $query->whereIn('template', $templates))
            ->with('design')
            ->latest('id')
            ->get()
            ->groupBy('player_id');

        return $ids->mapWithKeys(fn (int $id) => [$id => self::pick($owned->get($id) ?? collect())])->all();
    }

    /**
     * @param  Collection<int, Item>  $items  newest first
     * @return list<array{spot: string, template: string, name: string, colours: array<int, string>, pattern: string}>
     */
    private static function pick(Collection $items): array
    {
        $worn = [];
        foreach (self::SPOTS as $spot => $choices) {
            foreach ($choices as $template) {
                $item = $items->first(fn (Item $item) => $item->design->template === $template);
                if ($item !== null) {
                    $worn[] = [
                        'spot' => $spot,
                        'template' => $template->value,
                        'name' => $item->design->name,
                        'colours' => $item->design->colours,
                        'pattern' => $item->design->pattern,
                    ];
                    break;
                }
            }
        }

        return $worn;
    }
}
