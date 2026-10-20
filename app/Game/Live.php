<?php

namespace App\Game;

use App\Events\ValleyChanged;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Live updates over the websocket. Anything that changes the map marks its
 * valley; at the end of the request or the tick each marked valley gets one
 * "map" event, and every animal's map fetches what changed. A busy valley
 * gets at most one every few seconds: changes in between wait for the next
 * one, or for the tick, which sends any still owed. A broadcast that fails
 * never breaks the game: the next one catches up.
 */
final class Live
{
    /** The fewest seconds between two map events in one valley. */
    public const MAP_EVERY = 3;

    /** @var array<int, true> */
    private static array $valleys = [];

    public static function touch(int $valleyId): void
    {
        self::$valleys[$valleyId] = true;
    }

    public static function flush(): void
    {
        $valleys = array_keys(self::$valleys);
        self::$valleys = [];

        foreach ($valleys as $valleyId) {
            // The map's homes are read again on the next look.
            Cache::forget(Homes::mapKey($valleyId));

            if (Cache::add("live:map:{$valleyId}", true, self::MAP_EVERY)) {
                Cache::forget("live:map:{$valleyId}:owed");
                self::send(new ValleyChanged($valleyId, 'map'));
            } else {
                Cache::put("live:map:{$valleyId}:owed", true, 300);
            }
        }
    }

    /** A map event held back in a busy moment, sent now: the tick asks every minute. */
    public static function owed(int $valleyId): void
    {
        if (Cache::pull("live:map:{$valleyId}:owed")) {
            self::send(new ValleyChanged($valleyId, 'map'));
        }
    }

    public static function send(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
