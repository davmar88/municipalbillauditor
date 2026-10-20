<?php

namespace App\Game;

use App\Models\Faction;
use App\Models\Player;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A faction's banner: a pattern of its own, in two colours with an emblem,
 * or a picture its leader uploads. Only the leader and council change it.
 */
final class Banners
{
    public const PATTERNS = ['plain', 'stripes', 'chevron', 'cross', 'saltire', 'quarters', 'bend', 'border', 'chief'];

    public const EMBLEMS = ['', '🐷', '🐴', '🐶', '🐑', '🐐', '🐔', '🐦‍⬛', '⚔️', '🛡️', '🌾', '🍎', '🔥', '⭐', '🌙', '☀️', '👑', '🐺', '⚒️', '🌳'];

    /** The largest picture a faction can upload, in kilobytes. */
    public const MAX_KB = 1024;

    /** @return array{mode: string, pattern: string, colours: list<string>, emblem: string, image: string|null} */
    public static function of(Faction $faction): array
    {
        $banner = $faction->banner ?? [];
        $image = $faction->banner_path !== null ? route('faction.banner', ['faction' => $faction->id, 'v' => substr(md5($faction->banner_path), 0, 8)]) : null;

        return [
            'mode' => $image !== null && ($banner['mode'] ?? 'pattern') === 'image' ? 'image' : 'pattern',
            'pattern' => in_array($banner['pattern'] ?? null, self::PATTERNS, true) ? $banner['pattern'] : 'plain',
            'colours' => [$banner['colours'][0] ?? $faction->colour, $banner['colours'][1] ?? '#f5ecd7'],
            'emblem' => in_array($banner['emblem'] ?? '', self::EMBLEMS, true) ? ($banner['emblem'] ?? '') : '',
            'image' => $image,
        ];
    }

    /**
     * Design the banner from a pattern.
     *
     * @param  list<string>  $colours
     *
     * @throws GameException
     */
    public function design(Player $player, string $pattern, array $colours, string $emblem): Faction
    {
        $faction = self::mine($player);

        if (! in_array($pattern, self::PATTERNS, true) || ! in_array($emblem, self::EMBLEMS, true)) {
            throw new GameException('Pick a pattern and an emblem from the lists.');
        }

        foreach ($colours as $colour) {
            if (! preg_match('/^#[0-9a-f]{6}$/i', $colour)) {
                throw new GameException('Pick two colours for the banner.');
            }
        }

        $faction->forceFill(['banner' => ['mode' => 'pattern', 'pattern' => $pattern, 'colours' => array_values(array_slice($colours, 0, 2)), 'emblem' => $emblem]])->save();

        return $faction;
    }

    /**
     * Fly an uploaded picture as the banner.
     *
     * @throws GameException
     */
    public function upload(Player $player, UploadedFile $file): Faction
    {
        $faction = self::mine($player);
        $old = $faction->banner_path;
        $path = $file->store('banners', 'local');
        $faction->forceFill(['banner_path' => $path, 'banner' => [...($faction->banner ?? []), 'mode' => 'image']])->save();

        if ($old !== null && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return $faction;
    }

    /** Take a picture down, by its faction's leader or by an owner, and go back to the pattern. */
    public static function takeDown(Faction $faction): void
    {
        if ($faction->banner_path !== null) {
            Storage::disk('local')->delete($faction->banner_path);
        }

        $faction->forceFill(['banner_path' => null, 'banner' => [...($faction->banner ?? []), 'mode' => 'pattern']])->save();
    }

    /** @throws GameException */
    public static function mine(Player $player): Faction
    {
        $faction = $player->faction ?? throw new GameException('Join a faction first.');

        if (! $player->decides()) {
            throw new GameException("Only your faction's leader and council change its banner.");
        }

        return $faction;
    }
}
