<?php

namespace Tests\Feature\Game;

use App\Game\Clock;
use App\Game\GameException;
use App\Game\Pineapples;
use App\Game\Trees;
use App\Models\AppleEntry;
use App\Models\AppleTree;
use App\Models\Player;
use App\Payments\Cashouts;
use App\Payments\Packs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Apple trees: planting them with Pineapples, the Apples they grow, the
 * basket that waits for a harvest, and why they never pay more than a
 * cash-out.
 */
class TreesTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Trees $trees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->trees = app(Trees::class);
        $this->travelTo(Clock::now()->setTime(9, 0));
    }

    private function grower(int $pineapples = 2000): Player
    {
        $player = $this->player();
        Pineapples::award($player, $pineapples, 'Bought Pineapples', 'purchase:test-'.$player->id);

        return $player->fresh();
    }

    public function test_a_grown_apple_costs_less_than_a_bought_one_and_more_than_a_cash_out_pays(): void
    {
        foreach (['ZAR' => 'zar', 'USD' => 'usd'] as $currency => $price) {
            $pineapple = collect(Packs::pineapples())->min(fn (array $pack) => $pack[$price] / $pack['pineapples']);
            $grown = $pineapple * Trees::price() / Trees::apples();
            $bought = $pineapple * collect(Packs::apples())->min(fn (array $pack) => $pack['pineapples'] / $pack['apples']);
            $cashOut = config("game.cashout.per_100.{$currency}") / 100;

            $this->assertLessThan($bought, $grown, "A grown Apple is cheaper than a bought one in {$currency}.");
            $this->assertGreaterThan($cashOut * 1.1, $grown, "A grown Apple costs well over what a cash-out pays in {$currency}.");
        }
    }

    public function test_a_tree_is_planted_with_pineapples_and_fruits_after_two_days(): void
    {
        $player = $this->grower();

        $this->actingAs($player->user)->post(route('play.trees.plant'))->assertRedirect();

        $tree = AppleTree::sole();
        $this->assertSame(1100, $player->fresh()->pineapples);
        $this->assertSame(130, $tree->total);
        $this->assertSame(0, Trees::growth($tree)['basket']);

        $this->travel(2)->days();
        $this->assertSame(0, Trees::growth($tree->fresh())['basket'], 'A sapling grows nothing.');

        $this->travel(1)->days();
        $this->assertSame(5, Trees::growth($tree->fresh())['basket'], 'Five Apples a day once it fruits.');

        // A second tree leaves 200 Pineapples, too few for a third.
        $this->trees->plant($player->fresh());
        $this->expectExceptionMessage('You have 200 Pineapples.');
        $this->trees->plant($player->fresh());
    }

    public function test_a_full_basket_waits_for_the_harvest_and_nothing_is_lost(): void
    {
        $player = $this->grower();
        $tree = $this->trees->plant($player);

        $this->travel(2 + 10)->days();
        $this->assertSame(15, Trees::growth($tree->fresh())['basket'], 'The basket holds three days of Apples.');

        $this->actingAs($player->user)->post(route('play.trees.harvest'))
            ->assertInertiaFlash('toast.message', 'You harvested 15 Apples.');
        $this->assertSame(15, $player->fresh()->apples);
        $this->assertSame(15, (int) AppleEntry::query()->where('player_id', $player->id)->where('reference', 'like', 'tree:%')->sum('amount'));

        // It picks up again from the harvest, and gives every Apple in the end.
        $this->travel(1)->days();
        $this->assertSame(5, Trees::growth($tree->fresh())['basket']);

        for ($day = 0; $day < 40; $day++) {
            $this->travel(3)->days();

            try {
                $this->trees->harvest($player->fresh());
            } catch (GameException) {
                break;
            }
        }

        $this->assertSame(130, $player->fresh()->apples);
        $this->assertNotNull($tree->fresh()->finished_at);

        $this->expectExceptionMessage('No Apples are ready yet.');
        $this->trees->harvest($player->fresh());
    }

    public function test_an_animal_grows_five_trees_at_once_and_grown_apples_wait_before_a_cash_out(): void
    {
        $player = $this->grower(9000);

        foreach (range(1, 5) as $tree) {
            $this->trees->plant($player->fresh());
        }

        try {
            $this->trees->plant($player->fresh());
            $this->fail('Five trees at once.');
        } catch (GameException $e) {
            $this->assertStringContainsString('You have 5 trees growing', $e->getMessage());
        }

        $this->travel(3)->days();
        $this->assertSame(25, $this->trees->harvest($player->fresh()));
        $this->assertSame(25, Cashouts::held($player->fresh()), 'Grown Apples were paid for, so they wait like bought ones.');

        $this->actingAs($player->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page
            ->where('trees.price', 900)
            ->where('trees.most', 5)
            ->has('trees.trees', 5)
            ->where('trees.trees.0.grown', 5)
            ->where('trees.trees.0.basket', 0));
    }
}
