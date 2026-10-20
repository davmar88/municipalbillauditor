<?php

namespace App\Game;

use App\Events\ValleyChanged;
use Throwable;

/**
 * Live updates over the websocket. Anything that changes the map marks its
 * valley; at the end of the request or the tick each marked valley gets one
 * "map" event, and every animal's map fetches what changed. A broadcast
 * that fails never breaks the game: the next one catches up.
 */
final class Live
{
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
