<?php

namespace App\Game;

use App\Models\Faction;
use App\Models\Player;
use App\Models\Valley;
use App\Models\ValleyJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The job board (her ask, 2026-10-22): valley animals pin up jobs for Corn
 * and Rosettes, so lone wolves have reasons to fight too. Rob a member of a
 * faction, win a raid on a faction's land, hold off a raid on your own, or
 * bring goods to the board. The first animal to do a job is paid; a job
 * stays up for a day.
 */
final class ValleyJobs
{
    /** How many jobs the board keeps open. */
    public const OPEN = 4;

    public const HOURS = 24;

    /** Goods the board asks for. */
    public const GOODS = ['wood', 'stone', 'clay', 'straw', 'wool', 'eggs', 'dye'];

    /** Keep the board full. Safe to run every minute. */
    public function post(Valley $valley): void
    {
        $open = ValleyJob::query()->where('valley_id', $valley->id)->whereNull('done_at')->where('expires_at', '>', now())->count();

        for ($i = $open; $i < self::OPEN; $i++) {
            $this->postOne($valley);
        }
    }

    public function postOne(Valley $valley, ?string $kind = null): ValleyJob
    {
        $factions = Faction::query()->where('valley_id', $valley->id)->whereHas('members')->get();
        $kind ??= $factions->isEmpty() ? 'bring' : collect(['rob', 'raid', 'defend', 'bring'])->random();
        $target = $factions->isEmpty() ? null : $factions->random();
        $material = self::GOODS[array_rand(self::GOODS)];
        $amount = random_int(3, 8) * 5;

        [$corn, $rosettes] = match ($kind) {
            'rob' => [120, 30],
            'raid' => [200, 40],
            'defend' => [120, 30],
            default => [$amount * 4, 10],
        };

        return ValleyJob::create([
            'valley_id' => $valley->id,
            'kind' => $kind,
            'poster' => ValleyAnimals::NAMES[array_rand(ValleyAnimals::NAMES)],
            'target_faction_id' => in_array($kind, ['rob', 'raid'], true) ? $target?->id : null,
            'material' => $kind === 'bring' ? $material : null,
            'amount' => $kind === 'bring' ? $amount : 0,
            'reward_corn' => $corn,
            'reward_rosettes' => $rosettes,
            'expires_at' => CarbonImmutable::now()->addHours(self::HOURS),
        ]);
    }

    /**
     * A deed that may finish a job: a robbery of someone in a faction, a raid
     * won on a faction's land, or a raid held off.
     */
    public static function did(Player $player, string $kind, ?int $factionId = null): void
    {
        if ($player->npc) {
            return;
        }

        $job = ValleyJob::query()
            ->where('valley_id', $player->valley_id)
            ->where('kind', $kind)
            ->whereNull('done_at')
            ->where('expires_at', '>', now())
            ->when(in_array($kind, ['rob', 'raid'], true), fn ($query) => $query->where('target_faction_id', $factionId))
            ->oldest('id')
            ->first();

        if ($job !== null) {
            self::pay($player, $job);
        }
    }

    /**
     * Hand in the goods a "bring" job asks for, from your bag.
     *
     * @throws GameException
     */
    public function bring(Player $player, ValleyJob $job): ValleyJob
    {
        return DB::transaction(function () use ($player, $job) {
            $player = Players::lock($player);
            $job = ValleyJob::query()->lockForUpdate()->findOrFail($job->id);

            if ($job->valley_id !== $player->valley_id || $job->kind !== 'bring' || $job->done_at !== null || $job->expires_at->isPast()) {
                throw new GameException('That job is no longer on the board.');
            }

            if ($player->material($job->material) < $job->amount) {
                throw new GameException("The job needs {$job->amount} {$job->material} and you carry {$player->material($job->material)}. Gather some on the map.");
            }

            $materials = $player->materials;
            $materials[$job->material] -= $job->amount;
            $player->forceFill(['materials' => $materials])->save();

            return self::pay($player, $job);
        });
    }

    /**
     * The board, for the Valley page.
     *
     * @return list<array<string, mixed>>
     */
    public static function board(Player $player): array
    {
        $since = CarbonImmutable::now()->subHours(6);

        return ValleyJob::query()
            ->with(['target:id,name,colour', 'doer:id,name'])
            ->where('valley_id', $player->valley_id)
            ->where(fn ($query) => $query
                ->where(fn ($open) => $open->whereNull('done_at')->where('expires_at', '>', now()))
                ->orWhere('done_at', '>', $since))
            ->orderByRaw('done_at is not null')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (ValleyJob $job) => [
                'id' => $job->id,
                'kind' => $job->kind,
                'poster' => $job->poster,
                'says' => self::says($job),
                'target' => $job->target ? ['id' => $job->target->id, 'name' => $job->target->name, 'colour' => $job->target->colour] : null,
                'material' => $job->material,
                'amount' => $job->amount,
                'corn' => $job->reward_corn,
                'rosettes' => $job->reward_rosettes,
                'expiresAt' => $job->expires_at->toIso8601String(),
                'doneBy' => $job->doer?->name,
                'mine' => $job->target_faction_id !== null && $job->target_faction_id === $player->faction_id,
                'canBring' => $job->kind === 'bring' && $job->done_at === null && $player->material((string) $job->material) >= $job->amount,
            ])
            ->all();
    }

    public static function says(ValleyJob $job): string
    {
        $target = $job->target?->name ?? 'a faction';

        return match ($job->kind) {
            'rob' => "Rob any member of {$target}.",
            'raid' => "Win a raid on land held by {$target}.",
            'defend' => 'Hold off a raid on your faction\'s land.',
            default => "Bring {$job->amount} {$job->material} to the board.",
        };
    }

    private static function pay(Player $player, ValleyJob $job): ValleyJob
    {
        $job->forceFill(['done_by' => $player->id, 'done_at' => CarbonImmutable::now()])->save();
        Player::query()->whereKey($player->id)->increment('corn', $job->reward_corn);
        $player->corn += $job->reward_corn;
        Rosettes::award($player, $job->reward_rosettes, 'A job on the board', "job:{$job->id}", $player->valley->currentSeason());
        Notices::send($player->id, 'job', "📌 {$job->poster} paid you {$job->reward_corn} Corn and {$job->reward_rosettes} Rosettes: ".self::says($job), route('play.valley', [], false));

        return $job;
    }
}
