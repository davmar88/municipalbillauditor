<?php

namespace App\Game;

use App\Enums\Species;
use App\Models\Player;
use App\Models\User;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Making a user's animal, and the small things that happen on each visit.
 */
final class Players
{
    public const LANGUAGES = [
        // South Africa's official languages first, where the game began.
        'English', 'Afrikaans', 'isiZulu', 'isiXhosa', 'Sesotho', 'Setswana', 'Sepedi',
        'Xitsonga', 'siSwati', 'Tshivenda', 'isiNdebele',
        // Then the world's, so players anywhere can read each other.
        'Spanish', 'Portuguese', 'French', 'German', 'Italian', 'Dutch', 'Polish', 'Russian', 'Ukrainian',
        'Romanian', 'Greek', 'Czech', 'Hungarian', 'Swedish', 'Norwegian', 'Danish', 'Finnish',
        'Turkish', 'Arabic', 'Hebrew', 'Persian', 'Hindi', 'Bengali', 'Urdu', 'Punjabi', 'Tamil',
        'Telugu', 'Marathi', 'Chinese (Simplified)', 'Chinese (Traditional)', 'Japanese', 'Korean',
        'Vietnamese', 'Thai', 'Indonesian', 'Malay', 'Filipino', 'Swahili', 'Amharic', 'Hausa',
        'Yoruba', 'Igbo', 'Somali', 'Shona',
    ];

    /**
     * @throws GameException
     */
    public function start(User $user, Valley $valley, string $name, Species $species, bool $adult, string $language = 'English'): Player
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        if ($user->player()->exists()) {
            throw new GameException('You already have an animal in the valley.');
        }

        if (! $adult) {
            throw new GameException('The test season is for adults only. Please confirm you are 18 or older.');
        }

        if (! in_array($species, Species::playable(), true)) {
            throw new GameException("{$species->plural()} join the valley in a later season. Pick another animal.");
        }

        if (mb_strlen($name) < 2 || mb_strlen($name) > 20 || ! preg_match("/^[\pL][\pL '\-]*$/u", $name)) {
            throw new GameException("Your animal's name needs 2 to 20 letters, and may use spaces, hyphens and apostrophes.");
        }

        if (Player::query()->where('valley_id', $valley->id)->where('name', $name)->exists()) {
            throw new GameException("There is already an animal called {$name} in {$valley->name}. Pick another name.");
        }

        if (! in_array($language, self::LANGUAGES, true)) {
            $language = 'English';
        }

        return DB::transaction(fn () => Player::create([
            'user_id' => $user->id,
            'valley_id' => $valley->id,
            'region_id' => $valley->region('paddock')->id,
            'pos_x' => Geography::hub('paddock')[0],
            'pos_y' => Geography::hub('paddock')[1],
            'name' => $name,
            'species' => $species,
            'language' => $language,
            // A skin of water to start with: animals drink to stay alive.
            'materials' => [...array_fill_keys(Player::MATERIALS, 0), 'water' => 20],
            'corn' => (int) config('game.starting_corn'),
            'horseshoes' => Horseshoes::max(),
            'horseshoes_at' => CarbonImmutable::now(),
            'vitals_at' => CarbonImmutable::now(),
            'shield_until' => CarbonImmutable::now()->addDays((int) config('game.newcomer_shield_days')),
            'adult_confirmed_at' => CarbonImmutable::now(),
        ]));
    }

    /** Five Rosettes for the first visit of each day. */
    public function visit(Player $player): void
    {
        Census::seen($player);
        $today = Clock::today();

        // Day after day, the streak pays a little more.
        if ($player->streak_on?->toDateString() !== $today && config('game.streak.enabled')) {
            app(Streaks::class)->count($player);
        }

        if ($player->last_visit_on?->toDateString() === $today) {
            return;
        }

        $player->forceFill(['last_visit_on' => $today])->save();
        Rosettes::award($player, 5, 'First visit of the day', "visit:{$player->id}:{$today}", $player->valley->currentSeason());
    }

    /** How often an animal or a faction may change its name: often enough to fix a mistake, not to slip a bounty. */
    public const RENAME_DAYS = 7;

    /**
     * Change an animal's name, once a week, under the same rules as a new one.
     *
     * @throws GameException
     */
    public function rename(Player $player, string $name): void
    {
        $name = self::checkedName($player->valley, $name, $player->id);

        if ($name === $player->name) {
            return;
        }

        if ($player->renamed_at !== null && $player->renamed_at->gt(now()->subDays(self::RENAME_DAYS))) {
            throw new GameException('You changed your name lately. You can change it again from '.Clock::local($player->renamed_at->addDays(self::RENAME_DAYS))->format('j M H:i').'.');
        }

        $old = $player->name;
        $player->forceFill(['name' => $name, 'renamed_at' => now()])->save();

        if ($player->faction_id !== null) {
            Notices::send(Notices::members($player->faction_id)->reject(fn ($id) => $id === $player->id), 'faction', "{$old} goes by {$name} now.", route('play.animal', $player->id, false));
        }
    }

    /**
     * A tidy name that follows the rules and nobody else in the valley has.
     *
     * @throws GameException
     */
    public static function checkedName(Valley $valley, string $name, ?int $except = null): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 20 || ! preg_match("/^[\pL][\pL '\-]*$/u", $name)) {
            throw new GameException("Your animal's name needs 2 to 20 letters, and may use spaces, hyphens and apostrophes.");
        }

        if (Player::query()->where('valley_id', $valley->id)->where('name', $name)->when($except, fn ($query) => $query->whereKeyNot($except))->exists()) {
            throw new GameException("There is already an animal called {$name} in {$valley->name}. Pick another name.");
        }

        return $name;
    }

    public function setLanguage(Player $player, string $language): void
    {
        if (! in_array($language, self::LANGUAGES, true)) {
            throw new GameException('Pick a language from the list.');
        }

        $player->forceFill(['language' => $language])->save();
    }

    /** Players who have been around in the last week. */
    public static function activeCount(Valley $valley): int
    {
        return Player::query()
            ->where('valley_id', $valley->id)
            ->where('last_visit_on', '>=', Clock::now()->subDays(7)->toDateString())
            ->count();
    }

    /**
     * Lock and reload a player for a change that spends something.
     */
    public static function lock(Player $player): Player
    {
        $locked = Player::query()->lockForUpdate()->findOrFail($player->id);
        Movement::settle($locked);

        return $locked;
    }

    /** Why the animal can't write in chat or messages, or null when it can. */
    public static function silenced(Player $player): ?string
    {
        return $player->isMuted()
            ? 'Our team has stopped you writing until '.Clock::local($player->muted_until)->format('j M \\a\\t H:i').'.'
            : null;
    }

    /**
     * @throws GameException
     */
    public static function ensureFree(Player $player): void
    {
        if ($player->inPound()) {
            throw new GameException('You are in the pound until '.Clock::local($player->pound_until)->format('H:i').'. You can act again after that.');
        }
    }
}
