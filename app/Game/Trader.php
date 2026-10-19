<?php

namespace App\Game;

use App\Enums\ItemTemplate;
use App\Models\IrongateDeal;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pell, the Irongate pedlar, whose cart stands in the Market. She sells a
 * few plain basics for Corn, so there is always something to buy while the
 * players' shelves are bare. Her goods have no maker among the animals.
 */
final class Trader
{
    public const NAME = 'Pell';

    /** What she sells and for how much Corn. */
    public const STOCK = [
        'hat' => 40,
        'scarf' => 40,
        'gloves' => 80,
        'tonic' => 60,
        'cake' => 70,
        'shoes' => 90,
    ];

    /** The most an animal buys from her in a day. */
    public const DAILY = 3;

    /** Her cart, for the Market page. */
    public static function cart(Player $player): array
    {
        return [
            'name' => self::NAME,
            'left' => max(0, self::DAILY - self::boughtToday($player)),
            'goods' => collect(self::STOCK)->map(function (int $price, string $template) {
                $kind = ItemTemplate::from($template);

                return ['template' => $template, 'label' => $kind->label(), 'power' => $kind->power(), 'price' => $price];
            })->values()->all(),
        ];
    }

    private static function boughtToday(Player $player): int
    {
        return IrongateDeal::query()
            ->where('player_id', $player->id)
            ->where('kind', 'trader')
            ->where('created_at', '>=', Clock::now()->startOfDay())
            ->count();
    }

    /** Her own design for a kind of item, made once. */
    private static function design(ItemTemplate $kind): ItemDesign
    {
        return ItemDesign::query()->firstOrCreate(
            ['player_id' => null, 'template' => $kind->value, 'name' => self::NAME."'s ".$kind->label()],
            ['colours' => ['#8a6a4a', '#d9c7a3'], 'pattern' => 'plain', 'edition' => null, 'status' => 'approved'],
        );
    }

    /**
     * Buy one of her goods for Corn.
     *
     * @throws GameException
     */
    public function buy(Player $player, string $template): Item
    {
        $price = self::STOCK[$template] ?? throw new GameException('Pell doesn\'t sell that.');

        return DB::transaction(function () use ($player, $template, $price) {
            $player = Players::lock($player);
            Players::ensureFree($player);

            if (self::boughtToday($player) >= self::DAILY) {
                throw new GameException('Pell sells each animal '.self::DAILY.' things a day. Come back tomorrow.');
            }

            if ($player->corn < $price) {
                throw new GameException("That costs {$price} Corn. You have {$player->corn}.");
            }

            $design = ItemDesign::query()->lockForUpdate()->findOrFail(self::design(ItemTemplate::from($template))->id);
            $design->increment('made');

            $item = Item::query()->create([
                'item_design_id' => $design->id,
                'player_id' => $player->id,
                'serial' => $design->made,
            ]);

            $player->decrement('corn', $price);
            IrongateDeal::query()->create([
                'player_id' => $player->id,
                'kind' => 'trader',
                'material' => $template,
                'amount' => 1,
                'corn' => $price,
                'created_at' => CarbonImmutable::now(),
            ]);

            // Buying from her counts as buying in the market.
            Missions::record($player, 'buy');

            return $item;
        });
    }
}
