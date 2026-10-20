<?php

namespace App\Console\Commands;

use App\Models\GameUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Fills the public updates page from Git: every commit on the deployed
 * branch becomes a line, "Fixed" when its message says so and "New"
 * otherwise. Run on every deploy; commits already on the page are skipped.
 */
#[Signature('game:changelog {--since=2026-10-17 : The first day to read commits from}')]
#[Description('Add the commits since the last deploy to the updates page')]
class ImportChangelog extends Command
{
    /** Commits that are housekeeping, not news for players. */
    private const SKIP = ['/^merge\b/i', '/^revert\b/i', '/\b(tests?|lint|typo|format|refactor|chore|ci)\b/i', '/^(wip|bump)\b/i'];

    public function handle(): int
    {
        $separator = '---commit---';
        $result = Process::path(base_path())->run(['git', 'log', '--no-merges', '--since='.$this->option('since'), '--format='.$separator.'%H%n%cI%n%s%n%b']);

        if (! $result->successful()) {
            $this->warn('Git log could not be read: '.trim($result->errorOutput()));

            return self::SUCCESS;
        }

        $added = 0;

        foreach (array_filter(explode($separator, $result->output())) as $chunk) {
            $lines = explode("\n", trim($chunk));
            [$hash, $date, $subject] = [$lines[0] ?? '', $lines[1] ?? '', trim($lines[2] ?? '')];

            if ($hash === '' || $subject === '' || GameUpdate::query()->where('commit', $hash)->exists()) {
                continue;
            }

            if (collect(self::SKIP)->contains(fn (string $pattern) => preg_match($pattern, $subject))) {
                continue;
            }

            // The body, without the sign-off lines at the end.
            $body = collect(array_slice($lines, 3))
                ->reject(fn (string $line) => preg_match('/^(Co-Authored-By|Signed-off-by):/i', trim($line)))
                ->map(fn (string $line) => trim(ltrim(trim($line), '-* ')))
                ->filter()
                ->implode(' ');

            GameUpdate::create([
                'published_on' => CarbonImmutable::parse($date)->setTimezone(config('game.timezone'))->toDateString(),
                'kind' => preg_match('/^(fix|fixes|fixed|stop|correct)\b|\bfix(es|ed)?\b/i', $subject) ? 'fix' : 'new',
                'title' => Str::limit($subject, 117),
                'body' => Str::limit($body !== '' ? $body : $subject, 1997),
                'commit' => $hash,
            ]);
            $added++;
        }

        $this->info("Added {$added} updates from Git.");

        return self::SUCCESS;
    }
}
