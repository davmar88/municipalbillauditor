<?php

namespace App\Game;

use App\Enums\ItemTemplate;
use App\Models\Building;
use App\Models\Effect;
use App\Models\Faction;
use App\Models\Item;
use App\Models\Player;
use App\Models\Region;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Powers from items made on the Workshop's recipes. Using a copy uses it
 * up and starts its power: on its owner for a number of uses or hours, on
 * the owner's faction for some hours, or straight away. Nothing here hands
 * out horseshoes: those stay for Apples later.
 */
final class Powers
{
    public const GLOVES_USES = 5;

    public const GLOVES = 1.5;

    public const SHOES = 1.33;

    public const CLOAK = 0.5;

    public const RIBBON_HOURS = 3;

    public const BANNER = 1.15;

    public const BANNER_HOURS = 12;

    public const HEDGE = 1.15;

    public const HEDGE_HOURS = 24;

    public const BASKET = 1.25;

    public const BASKET_HOURS = 12;

    public const BELL = 0.25;

    public const BELL_HOURS = 24;

    /**
     * Use a powered item. It is used up and its power starts.
     *
     * @return string What happened, to tell the player.
     *
     * @throws GameException
     */
    public function use(Player $player, Item $item): string
    {
        return DB::transaction(function () use ($player, $item) {
            $player = Players::lock($player);
            $item = Item::query()->with('design')->lockForUpdate()->findOrFail($item->id);
            $template = $item->design->template;

            if ($item->player_id !== $player->id || $item->used_at !== null) {
                throw new GameException("That item isn't yours to use.");
            }

            if (! $template->hasPower()) {
                throw new GameException("A {$template->label()} is for wearing and selling. It has no power to use.");
            }

            if ($item->openListing()->exists()) {
                throw new GameException('Take it off your shelves before using it.');
            }

            // A tonic is the one thing an animal in the pound can use.
            if ($template !== ItemTemplate::Tonic) {
                Players::ensureFree($player);
            }

            $told = match ($template) {
                ItemTemplate::Gloves => $this->charge($player, 'gloves', self::GLOVES_USES, 'Your gloves are on: your next 5 gathers bring in half as much again.'),
                ItemTemplate::Shoes => $this->charge($player, 'shoes', 1, 'Your Iron Shoes are on: your horseshoes count a third more in your next raid or defence.'),
                ItemTemplate::Cloak => $this->charge($player, 'cloak', 1, 'You put on the Night Cloak: your next crime is half as likely to be caught.'),
                ItemTemplate::Tonic => $this->tonic($player),
                ItemTemplate::Ribbon => $this->time(['player_id' => $player->id], 'ribbon', self::RIBBON_HOURS, 'You pinned on the Prize Ribbon: double Rosettes'),
                ItemTemplate::Banner => $this->time(['faction_id' => $this->faction($player)->id], 'banner', self::BANNER_HOURS, "You raised the War Banner: your faction's raids hit 15% harder"),
                ItemTemplate::Basket => $this->time(['faction_id' => $this->faction($player)->id], 'basket', self::BASKET_HOURS, 'You shared the Feast Basket: your faction gathers a quarter more'),
                ItemTemplate::Bell => $this->time(['faction_id' => $this->faction($player)->id], 'bell', self::BELL_HOURS, 'You hung the Watch Bell: criminals who target your faction are likelier to be caught'),
                ItemTemplate::Hedge => $this->hedge($player),
                ItemTemplate::Trowel => $this->trowel($player),
                ItemTemplate::Sheaf => $this->sheaf($player),
                default => throw new GameException('That item has no power to use.'),
            };

            $item->forceFill(['used_at' => CarbonImmutable::now()])->save();

            return $told;
        });
    }

    /** Gathering: gloves use one of their charges; a Feast Basket helps the whole faction. */
    public static function gathering(Player $player): float
    {
        $boost = self::spend(['player_id' => $player->id], 'gloves') ? self::GLOVES : 1.0;

        if ($player->faction_id !== null && self::active(['faction_id' => $player->faction_id], 'basket')) {
            $boost *= self::BASKET;
        }

        return $boost;
    }

    /** Joining a battle: Iron Shoes, if on, are used up and the commitment counts more. */
    public static function commitment(Player $player): float
    {
        return self::spend(['player_id' => $player->id], 'shoes') ? self::SHOES : 1.0;
    }

    /** A War Banner makes a faction's raids hit harder. */
    public static function attack(int $factionId): float
    {
        return self::active(['faction_id' => $factionId], 'banner') ? self::BANNER : 1.0;
    }

    /** A Thorn Hedge makes a region defend harder for the faction that planted it. */
    public static function defence(Region $region): float
    {
        return $region->faction_id !== null
            && self::active(['region_id' => $region->id, 'faction_id' => $region->faction_id], 'hedge')
            ? self::HEDGE
            : 1.0;
    }

    /** Committing a crime: a Night Cloak, if on, is used up. */
    public static function cloak(Player $player): bool
    {
        return self::spend(['player_id' => $player->id], 'cloak');
    }

    /** How much likelier criminals are to be caught robbing a faction with a Watch Bell. */
    public static function bell(?int $factionId): float
    {
        return $factionId !== null && self::active(['faction_id' => $factionId], 'bell') ? self::BELL : 0.0;
    }

    /** Rosettes count double while a Prize Ribbon is pinned on. */
    public static function rosettes(int $playerId): int
    {
        return self::active(['player_id' => $playerId], 'ribbon') ? 2 : 1;
    }

    /**
     * What is working for an animal right now, from its own items and its faction's.
     *
     * @return list<array{kind: string, label: string, left: string}>
     */
    public static function summary(Player $player): array
    {
        $effects = Effect::query()
            ->active()
            ->where(fn ($query) => $query
                ->where('player_id', $player->id)
                ->when($player->faction_id !== null, fn ($faction) => $faction->orWhere(fn ($own) => $own->where('faction_id', $player->faction_id)->whereNull('region_id'))))
            ->orderBy('id')
            ->get();

        $labels = [
            'gloves' => 'Gathering Gloves',
            'shoes' => 'Iron Shoes',
            'cloak' => 'Night Cloak',
            'ribbon' => 'Prize Ribbon',
            'banner' => 'War Banner',
            'basket' => 'Feast Basket',
            'bell' => 'Watch Bell',
        ];

        return $effects
            ->filter(fn (Effect $effect) => isset($labels[$effect->kind]))
            ->map(fn (Effect $effect) => [
                'kind' => $effect->kind,
                'label' => $labels[$effect->kind],
                'left' => $effect->expires_at !== null
                    ? 'until '.Clock::local($effect->expires_at)->format('H:i')
                    : ($effect->charges === 1 ? '1 use left' : "{$effect->charges} uses left"),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $owner
     */
    private static function active(array $owner, string $kind): bool
    {
        return Effect::query()->where($owner)->where('kind', $kind)->active()->exists();
    }

    /**
     * Use one charge of a counted effect, if there is one.
     *
     * @param  array<string, int>  $owner
     */
    private static function spend(array $owner, string $kind): bool
    {
        $effect = Effect::query()
            ->where($owner)
            ->where('kind', $kind)
            ->whereNull('expires_at')
            ->where('charges', '>', 0)
            ->lockForUpdate()
            ->first();

        if ($effect === null) {
            return false;
        }

        $effect->charges === 1 ? $effect->delete() : $effect->decrement('charges');

        return true;
    }

    /** Add uses to a counted effect on the player. */
    private function charge(Player $player, string $kind, int $uses, string $told): string
    {
        $effect = Effect::query()->where('player_id', $player->id)->where('kind', $kind)->whereNull('expires_at')->lockForUpdate()->first();

        if ($effect) {
            $effect->increment('charges', $uses);
        } else {
            Effect::create(['player_id' => $player->id, 'kind' => $kind, 'charges' => $uses]);
        }

        return $told;
    }

    /**
     * Start a timed effect, or add the hours to one already running.
     *
     * @param  array<string, int>  $owner
     */
    private function time(array $owner, string $kind, int $hours, string $told): string
    {
        $effect = Effect::query()->where($owner)->where('kind', $kind)->whereNotNull('expires_at')->lockForUpdate()->first();
        $from = $effect && $effect->expires_at->isFuture() ? $effect->expires_at->toImmutable() : CarbonImmutable::now();
        $until = $from->addHours($hours);

        if ($effect) {
            $effect->forceFill(['expires_at' => $until])->save();
        } else {
            Effect::create([...$owner, 'kind' => $kind, 'expires_at' => $until]);
        }

        return "{$told} until ".Clock::local($until)->format('H:i').'.';
    }

    private function tonic(Player $player): string
    {
        if (! $player->inPound()) {
            throw new GameException("You're not in the pound. Keep the tonic for when you are.");
        }

        $player->forceFill(['pound_until' => null])->save();

        return 'You drank the Willow Tonic and walked out of the pound.';
    }

    private function hedge(Player $player): string
    {
        $faction = $this->faction($player);
        $region = $player->location();

        if ($region->faction_id !== $faction->id) {
            throw new GameException("Plant it on your faction's land: walk to a region it holds first.");
        }

        return $this->time(['faction_id' => $faction->id, 'region_id' => $region->id], 'hedge', self::HEDGE_HOURS, "You planted a Thorn Hedge round {$region->name}: it defends 15% harder");
    }

    private function trowel(Player $player): string
    {
        $faction = $this->faction($player);
        $region = $player->location();
        $building = Building::query()->where('region_id', $region->id)->where('faction_id', $faction->id)->lockForUpdate()->first();

        if ($building === null || $region->faction_id !== $faction->id) {
            throw new GameException("Stand in a region where your faction's building is going up.");
        }

        if ($building->isReady()) {
            throw new GameException("The {$building->kind->label()} in {$region->name} is already finished.");
        }

        $building->forceFill(['ready_at' => CarbonImmutable::now()])->save();

        return "You finished the {$building->kind->label()} in {$region->name}.";
    }

    private function sheaf(Player $player): string
    {
        $faction = $this->faction($player);
        $season = $player->valley->currentSeason() ?? throw new GameException('The season is changing. Try again in a moment.');

        if ($season->valley->house()?->id !== $faction->id) {
            throw new GameException('Only the House can use a Golden Sheaf. Sell it to them instead.');
        }

        Season::query()->whereKey($season->id)->increment('bonus_law_changes');

        return 'You laid the Golden Sheaf on the Farmhouse table: the House has one more law change.';
    }

    /**
     * @throws GameException
     */
    private function faction(Player $player): Faction
    {
        return $player->faction ?? throw new GameException("That's for a faction. Join one first, or sell it to someone who has.");
    }
}
