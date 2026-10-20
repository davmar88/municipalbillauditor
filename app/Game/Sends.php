<?php

namespace App\Game;

use App\Models\Player;
use App\Models\Send;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sending Corn, Apples, Pineapples or materials to another animal, from
 * the map or its profile. Each has a daily limit, so sending stays a gift
 * and doesn't become a way to feed one account from others; Apples sent
 * wait before they can be cashed out, like bought ones.
 */
final class Sends
{
    /** @return array<string, int> The most an animal can send of each in a day. */
    public static function limits(): array
    {
        return array_map('intval', (array) config('game.sends'));
    }

    /** How much of something an animal can send, in its own words. */
    public static function label(string $what): string
    {
        return match ($what) {
            'corn' => 'Corn',
            'apples' => 'Apples',
            'pineapples' => 'Pineapples',
            default => $what,
        };
    }

    /** What's left to send today, of each kind. */
    public static function left(Player $player, string $what): int
    {
        $limit = self::limits()[$what] ?? self::limits()['material'];
        $sent = (int) Send::query()->where('from_player_id', $player->id)->where('what', $what)->where('created_at', '>=', Clock::startOfToday())->sum('amount');

        return max(0, $limit - $sent);
    }

    /**
     * @throws GameException
     */
    public function send(Player $from, Player $to, string $what, int $amount): void
    {
        if ($from->is($to)) {
            throw new GameException("You can't send things to yourself.");
        }

        if ($from->valley_id !== $to->valley_id) {
            throw new GameException("{$to->name} lives in another valley.");
        }

        if ($amount < 1) {
            throw new GameException('Send at least 1.');
        }

        $material = ! in_array($what, ['corn', 'apples', 'pineapples'], true);

        if ($material && ! in_array($what, Bases::MATERIALS, true)) {
            throw new GameException('Pick something to send.');
        }

        DB::transaction(function () use ($from, $to, $what, $amount, $material) {
            // Both animals, lowest id first so two sends crossing can't deadlock.
            [$first, $second] = $from->id < $to->id ? [$from, $to] : [$to, $from];
            $first = Players::lock($first);
            $second = Players::lock($second);
            [$from, $to] = $first->id === $from->id ? [$first, $second] : [$second, $first];
            Gifts::ensureCanGive($from, $to);

            $left = self::left($from, $what);
            $label = self::label($what);

            if ($amount > $left) {
                throw new GameException($left > 0 ? "You can send {$left} more {$label} today." : "You've sent all the {$label} you can today. Send more tomorrow.");
            }

            $reference = 'gift:'.Str::uuid();

            match (true) {
                $what === 'corn' => $this->corn($from, $to, $amount),
                $what === 'apples' => (function () use ($from, $to, $amount, $reference) {
                    Apples::spend($from, $amount, "Sent to {$to->name}", "{$reference}:out");
                    Apples::award($to, $amount, "From {$from->name}", "{$reference}:in");
                })(),
                $what === 'pineapples' => (function () use ($from, $to, $amount, $reference) {
                    Pineapples::spend($from, $amount, "Sent to {$to->name}", "{$reference}:out");
                    Pineapples::award($to, $amount, "From {$from->name}", "{$reference}:in");
                })(),
                $material => $this->material($from, $to, $what, $amount),
            };

            Send::create(['from_player_id' => $from->id, 'to_player_id' => $to->id, 'what' => $what, 'amount' => $amount]);
            Notices::send($to->id, 'gift', "🎁 {$from->name} sent you {$amount} {$label}.", route('play.animal', $from, false));
        });
    }

    private function corn(Player $from, Player $to, int $amount): void
    {
        if ($from->corn < $amount) {
            throw new GameException("You have {$from->corn} Corn.");
        }

        $from->decrement('corn', $amount);
        $to->increment('corn', $amount);
    }

    private function material(Player $from, Player $to, string $what, int $amount): void
    {
        $have = $from->material($what);

        if ($have < $amount) {
            throw new GameException("You have {$have} {$what}.");
        }

        $mine = $from->materials ?? [];
        $mine[$what] = $have - $amount;
        $from->forceFill(['materials' => $mine])->save();
        $theirs = $to->materials ?? [];
        $theirs[$what] = (int) ($theirs[$what] ?? 0) + $amount;
        $to->forceFill(['materials' => $theirs])->save();
    }
}
