<?php

namespace App\Game;

use App\Enums\ItemTemplate;
use App\Models\Item;
use App\Models\Player;

/**
 * What an animal wears on its profile: the shop items that go on a body, one
 * per spot. A copy is worn until its power is used up, and one on a shop shelf
 * isn't worn. With two of a kind, the newest copy is the one worn.
 */
final class Wardrobe
{
    /**
     * Each spot and the items that go there, the first owned one winning.
     *
     * @var array<string, list<ItemTemplate>>
     */
    public const SPOTS = [
        'hat' => [ItemTemplate::Hat],
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
        $templates = array_merge(...array_values(self::SPOTS));
        $items = $player->items()
            ->whereDoesntHave('openListing')
            ->whereHas('design', fn ($query) => $query->whereIn('template', $templates))
            ->with('design')
            ->latest('id')
            ->get();

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
