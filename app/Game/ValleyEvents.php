<?php

namespace App\Game;

use App\Models\Player;
use App\Models\Region;
use App\Models\Valley;
use App\Models\ValleyEvent;
use App\Models\ValleyEventEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Valley events (her ask, 2026-10-22): twice a day, without warning,
 * something happens on one patch of land for an hour. The first five
 * animals to walk there and take part win. One in the morning, one in the
 * late afternoon.
 */
final class ValleyEvents
{
    public const MINUTES = 60;

    public const PLACES = 5;

    /** Two windows a day, by the valley's clock: [from hour, latest hour]. */
    public const WINDOWS = [[9, 12], [16, 19]];

    /**
     * What each event is: what it costs to take part and what it pays.
     *
     * @var array<string, array{label: string, emoji: string, says: string, horseshoes: int, corn: int, pays: array{corn?: int, rosettes?: int, horseshoes?: int, gold?: int}}>
     */
    public const KINDS = [
        'gold_rush' => [
            'label' => 'Gold rush', 'emoji' => '💰', 'says' => 'Gold glints in the stream. Pan for it.',
            'horseshoes' => 1, 'corn' => 0, 'pays' => ['corn' => 60, 'gold' => 2, 'rosettes' => 10],
        ],
        'wolves' => [
            'label' => 'Wolf pack', 'emoji' => '🐺', 'says' => 'Wolves are circling the herds. Drive them off.',
            'horseshoes' => 2, 'corn' => 0, 'pays' => ['corn' => 60, 'rosettes' => 30],
        ],
        'flood' => [
            'label' => 'Flood', 'emoji' => '🌊', 'says' => 'The river burst its banks. Help shore them up.',
            'horseshoes' => 1, 'corn' => 0, 'pays' => ['corn' => 50, 'rosettes' => 20],
        ],
        'merchant' => [
            'label' => 'Travelling merchant', 'emoji' => '🛒', 'says' => 'A merchant has horseshoes going cheap.',
            'horseshoes' => 0, 'corn' => 40, 'pays' => ['horseshoes' => 5],
        ],
    ];

    /** Start the day's events when their windows come. Safe to run every minute. */
    public function schedule(Valley $valley): ?ValleyEvent
    {
        $now = Clock::now();
        $today = ValleyEvent::query()->where('valley_id', $valley->id)->where('starts_at', '>=', Clock::startOfToday())->count();

        foreach (self::WINDOWS as $i => [$from, $latest]) {
            if ($today !== $i || $now->hour < $from) {
                continue;
            }

            // Somewhere in the window, not on the hour: about one chance in 40 a minute, and surely by its last hour.
            if ($now->hour < $latest && random_int(1, 40) !== 1) {
                return null;
            }

            return $this->start($valley);
        }

        return null;
    }

    /** Start an event now on a random patch of ordinary land. */
    public function start(Valley $valley, ?string $kind = null): ?ValleyEvent
    {
        $places = Region::query()->where('valley_id', $valley->id)->where('wild', false)->get()
            ->reject(fn (Region $region) => $region->isCity() || $region->isScrub() || $region->isIronfield());

        if ($places->isEmpty()) {
            return null;
        }

        $region = $places->random();

        $kind ??= array_rand(self::KINDS);
        $info = self::KINDS[$kind];
        $now = CarbonImmutable::now();
        $event = ValleyEvent::create([
            'valley_id' => $valley->id,
            'kind' => $kind,
            'region_id' => $region->id,
            'places' => self::PLACES,
            'starts_at' => $now,
            'ends_at' => $now->addMinutes(self::MINUTES),
        ]);

        Notices::send(Notices::valley($valley->id), 'event', "{$info['emoji']} {$info['label']} at {$region->name}! {$info['says']} The first ".self::PLACES.' animals there win. Until '.Clock::local($event->ends_at)->format('H:i').'.', route('play', [], false));

        return $event;
    }

    /**
     * Take part in an event where you stand.
     *
     * @return string What you won.
     *
     * @throws GameException
     */
    public function join(Player $player, ValleyEvent $event): string
    {
        $info = self::KINDS[$event->kind] ?? throw new GameException('That event is over.');

        return DB::transaction(function () use ($player, $event, $info) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $event = ValleyEvent::query()->with('region')->lockForUpdate()->findOrFail($event->id);

            if ($event->valley_id !== $player->valley_id || $event->ends_at->isPast()) {
                throw new GameException('That event is over.');
            }

            Movement::ensureStandingIn($player, $event->region, 'take part');

            if (ValleyEventEntry::query()->where('valley_event_id', $event->id)->where('player_id', $player->id)->exists()) {
                throw new GameException("You've already taken part.");
            }

            if (ValleyEventEntry::query()->where('valley_event_id', $event->id)->count() >= $event->places) {
                throw new GameException("All {$event->places} places went to quicker animals. Watch for the next one.");
            }

            if ($info['corn'] > 0) {
                if ($player->corn < $info['corn']) {
                    throw new GameException("That costs {$info['corn']} Corn and you have {$player->corn}.");
                }
                $player->corn -= $info['corn'];
                $player->save();
            }

            if ($info['horseshoes'] > 0) {
                Horseshoes::spend($player, $info['horseshoes']);
            }

            ValleyEventEntry::create(['valley_event_id' => $event->id, 'player_id' => $player->id]);

            $pays = $info['pays'];
            $won = [];

            if (($pays['corn'] ?? 0) > 0) {
                $player->corn += $pays['corn'];
                $won[] = "{$pays['corn']} Corn";
            }

            if (($pays['gold'] ?? 0) > 0) {
                $materials = $player->materials ?? [];
                $materials['gold'] = (int) ($materials['gold'] ?? 0) + $pays['gold'];
                $player->materials = $materials;
                $won[] = "{$pays['gold']} gold";
            }

            $player->save();

            if (($pays['horseshoes'] ?? 0) > 0) {
                Horseshoes::give($player, $pays['horseshoes']);
                $won[] = "{$pays['horseshoes']} horseshoes";
            }

            if (($pays['rosettes'] ?? 0) > 0) {
                Rosettes::award($player, $pays['rosettes'], $info['label'], "event:{$event->id}:{$player->id}", $player->valley->currentSeason());
                $won[] = "{$pays['rosettes']} Rosettes";
            }

            return "{$info['emoji']} You made it to the {$info['label']} in time: ".implode(', ', $won).'.';
        });
    }

    /**
     * Events on now, for the map and the Valley page.
     *
     * @return list<array<string, mixed>>
     */
    public static function live(Player $player): array
    {
        return ValleyEvent::query()
            ->with('region:id,name,key')
            ->withCount('entries')
            ->where('valley_id', $player->valley_id)
            ->where('ends_at', '>', CarbonImmutable::now())
            ->orderBy('ends_at')
            ->get()
            ->map(fn (ValleyEvent $event) => [
                'id' => $event->id,
                'kind' => $event->kind,
                'label' => self::KINDS[$event->kind]['label'] ?? $event->kind,
                'emoji' => self::KINDS[$event->kind]['emoji'] ?? '✨',
                'says' => self::KINDS[$event->kind]['says'] ?? '',
                'costs' => self::costs($event->kind),
                'pays' => self::pays($event->kind),
                'regionId' => $event->region_id,
                'region' => $event->region->name,
                'endsAt' => $event->ends_at->toIso8601String(),
                'left' => max(0, $event->places - $event->entries_count),
                'joined' => ValleyEventEntry::query()->where('valley_event_id', $event->id)->where('player_id', $player->id)->exists(),
            ])
            ->all();
    }

    public static function costs(string $kind): string
    {
        $info = self::KINDS[$kind];

        return match (true) {
            $info['corn'] > 0 => "{$info['corn']} Corn",
            $info['horseshoes'] > 0 => $info['horseshoes'].' '.($info['horseshoes'] === 1 ? 'horseshoe' : 'horseshoes'),
            default => 'nothing',
        };
    }

    public static function pays(string $kind): string
    {
        $pays = self::KINDS[$kind]['pays'];
        $names = ['corn' => 'Corn', 'gold' => 'gold', 'horseshoes' => 'horseshoes', 'rosettes' => 'Rosettes'];

        return implode(', ', array_map(fn (string $key, int $amount) => "{$amount} {$names[$key]}", array_keys($pays), $pays));
    }
}
