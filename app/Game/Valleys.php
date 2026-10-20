<?php

namespace App\Game;

use App\Models\Player;
use App\Models\User;
use App\Models\Valley;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * When a valley is full, the next one opens. A valley takes newcomers until
 * it has as many animals active this week as it holds; the owner can also
 * close one to newcomers early, or open the next one by hand. Newcomers go
 * to the oldest valley with room, so each fills before the next is used.
 * Animals already in a valley stay where they are.
 */
final class Valleys
{
    /** Names for the valleys after Hollowmere, in the order they open. */
    public const NAMES = ['Briarwood', 'Thornvale', 'Marrowmead', 'Ashenford', 'Wolds End', 'Cobble Hollow', 'Fenwick Downs', 'Larkspur Vale'];

    /** Animals active this week that fill a valley. */
    public static function fullAt(): int
    {
        return (int) config('game.valleys.full_at');
    }

    /** How full a valley is: animals active this week, and newcomers who joined this week but haven't played yet. */
    public static function filled(Valley $valley): int
    {
        $since = Clock::now()->subDays(7);

        return Player::query()
            ->where('valley_id', $valley->id)
            ->where(fn ($query) => $query->where('last_visit_on', '>=', $since->toDateString())->orWhere('created_at', '>=', $since))
            ->count();
    }

    /** Whether a valley takes newcomers: not closed by the owner, and not full. */
    public static function hasRoom(Valley $valley): bool
    {
        return ! $valley->closed_to_newcomers && self::filled($valley) < self::fullAt();
    }

    /** The valley a newcomer joins: the oldest with room. Null when every one is full. */
    public static function withRoom(): ?Valley
    {
        return Valley::query()->orderBy('id')->get()->first(fn (Valley $valley) => self::hasRoom($valley));
    }

    /** The name the next valley will take. */
    public static function nextName(): string
    {
        $taken = Valley::query()->pluck('name')->all();

        foreach (self::NAMES as $name) {
            if (! in_array($name, $taken, true)) {
                return $name;
            }
        }

        return 'Valley '.(Valley::query()->count() + 1);
    }

    /** Where a newcomer will start, for the start page: the valley with room, or the one that opens for them. */
    public static function preview(): string
    {
        return self::withRoom()?->name ?? self::nextName();
    }

    /**
     * The valley a newcomer joins, opening the next one when every valley
     * is full. Only one request opens it, however many arrive together.
     */
    public static function forNewcomer(): Valley
    {
        if (($valley = self::withRoom()) !== null) {
            return $valley;
        }

        return Cache::lock('valleys:open', 30)->block(20, fn () => self::withRoom() ?? self::open());
    }

    /** Lay out the next valley with its first season, and tell the owners. */
    public static function open(?string $name = null): Valley
    {
        $name ??= self::nextName();
        $valley = app(ValleyBuilder::class)->build($name);

        $admins = User::query()->where('is_admin', true)->with('player:id,user_id')->get()->pluck('player.id')->filter();
        Notices::send($admins, 'valley', "🌄 {$name} opened for newcomers: every valley before it is full or closed.", route('admin.valleys', [], false));

        return $valley;
    }

    /**
     * Every valley with how full it is, for the owner's page.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function overview(): Collection
    {
        $totals = DB::table('players')->groupBy('valley_id')->selectRaw('valley_id, COUNT(*) AS total')->pluck('total', 'valley_id');
        $newcomers = self::withRoom()?->id;

        return Valley::query()->orderBy('id')->get()->map(fn (Valley $valley) => [
            'id' => $valley->id,
            'name' => $valley->name,
            'active' => self::filled($valley),
            'total' => (int) ($totals[$valley->id] ?? 0),
            'factions' => $valley->factions()->count(),
            'season' => $valley->currentSeason()?->number,
            'closed' => (bool) $valley->closed_to_newcomers,
            'takingNewcomers' => $valley->id === $newcomers,
            'openedAt' => $valley->created_at?->toIso8601String(),
        ]);
    }
}
