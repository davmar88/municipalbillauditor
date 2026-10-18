<?php

namespace App\Game;

use App\Enums\FactionRole;
use App\Models\Notice;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The bell: game news for animals, written as things happen. A notice
 * sent inside a transaction goes or stays with what it reports.
 */
final class Notices
{
    /**
     * @param  int|iterable<int>  $players
     */
    public static function send(int|iterable $players, string $kind, string $text, ?string $url = null): void
    {
        $ids = collect(is_int($players) ? [$players] : $players)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $now = CarbonImmutable::now();

        foreach ($ids->chunk(500) as $chunk) {
            Notice::query()->insert($chunk->map(fn (int $id) => [
                'player_id' => $id,
                'kind' => $kind,
                'text' => Str::limit($text, 297),
                'url' => $url,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }
    }

    /** @return Collection<int, int> The ids of a faction's members. */
    public static function members(?int $factionId): Collection
    {
        return $factionId === null ? collect() : Player::query()->where('faction_id', $factionId)->pluck('id');
    }

    /** @return Collection<int, int> The ids of a faction's leader and council. */
    public static function deciders(int $factionId): Collection
    {
        return Player::query()
            ->where('faction_id', $factionId)
            ->whereIn('faction_role', [FactionRole::Leader, FactionRole::Council])
            ->pluck('id');
    }

    /** @return Collection<int, int> Every animal in a valley. */
    public static function valley(int $valleyId): Collection
    {
        return Player::query()->where('valley_id', $valleyId)->pluck('id');
    }

    public static function unread(Player $player): int
    {
        return Notice::query()->where('player_id', $player->id)->whereNull('read_at')->count();
    }

    /**
     * The latest news for the bell, newest first.
     *
     * @return Collection<int, array{id: int, kind: string, text: string, url: string|null, at: string, read: bool}>
     */
    public static function latest(Player $player, int $limit = 30, int $after = 0): Collection
    {
        return Notice::query()
            ->where('player_id', $player->id)
            ->where('id', '>', $after)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Notice $notice) => [
                'id' => $notice->id,
                'kind' => $notice->kind,
                'text' => $notice->text,
                'url' => $notice->url,
                'at' => $notice->created_at->toIso8601String(),
                'read' => $notice->read_at !== null,
            ]);
    }

    public static function markRead(Player $player): void
    {
        Notice::query()->where('player_id', $player->id)->whereNull('read_at')->update(['read_at' => CarbonImmutable::now()]);
    }
}
