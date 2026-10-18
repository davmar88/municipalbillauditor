<?php

namespace Tests\Feature\Game;

use App\Game\Clock;
use App\Game\GameException;
use App\Game\Gathering;
use App\Game\Stock;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Each region holds only so much to gather: it runs out, grows back
 * steadily, and the map says how much is left.
 */
class StockTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->travelTo(Clock::now()->setTime(9, 0));
    }

    private function cornField(): Region
    {
        return Region::query()->where('valley_id', $this->valley->id)->where('produces', 'corn')->where('wild', false)->firstOrFail();
    }

    public function test_gathering_takes_from_the_region_and_it_grows_back(): void
    {
        $field = $this->cornField();
        $player = $this->player();
        $this->stand($player, $field->key);

        $this->assertSame(['left' => 400, 'max' => 400, 'from' => null], Stock::state($field));

        ['amount' => $amount, 'tax' => $tax] = app(Gathering::class)->gather($player, $field);
        $taken = $amount + $tax;
        $this->assertSame(400 - $taken, Stock::state($field->fresh())['left']);

        // 80 grow back an hour, until it is full.
        $this->travel(15)->minutes();
        $this->assertSame(min(400, 400 - $taken + 20), Stock::state($field->fresh())['left']);
        $this->travel(10)->hours();
        $this->assertSame(400, Stock::state($field->fresh())['left']);
    }

    public function test_an_empty_region_cannot_be_gathered_until_some_grows_back(): void
    {
        $field = $this->cornField();
        $field->forceFill(['stock' => 0, 'stock_at' => now()])->save();
        $player = $this->player();
        $this->stand($player, $field->key);

        $this->actingAs($player->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where("regions.{$this->index($field)}.stock.left", 0)
            ->where("regions.{$this->index($field)}.stock.nextAt", Stock::nextAt($field->fresh())->toIso8601String()));

        try {
            app(Gathering::class)->gather($player->fresh(), $field->fresh());
            $this->fail('Picked clean.');
        } catch (GameException $e) {
            $this->assertStringContainsString("{$field->name} is picked clean. More grows back by 09:01", $e->getMessage());
        }

        $this->assertSame(10, $player->fresh()->horseshoes, 'Nothing was spent.');

        // A little grows back, and that little is all a gather can take.
        $this->travel(6)->minutes();
        ['amount' => $amount, 'tax' => $tax] = app(Gathering::class)->gather($player->fresh(), $field->fresh());
        $this->assertSame(8, $amount + $tax);
        $this->assertSame(0, Stock::state($field->fresh())['left']);
    }

    private function index(Region $region): int
    {
        return Region::query()->where('valley_id', $this->valley->id)->where('wild', false)->orderBy('row')->orderBy('col')->pluck('id')->search($region->id);
    }
}
