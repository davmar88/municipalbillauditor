<?php

namespace App\Services\Claude;

use App\Audit\Money;
use App\Models\ClaudeUsageRecord;
use App\Models\Organisation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Prices Claude calls from the per-million-token prices in config and
 * records them against the organisation and the bill or dispute.
 */
class ClaudeCosts
{
    /**
     * @param  string  $purpose  What the call was for, such as bill_read or reply_read.
     */
    public function record(ClaudeUsage $usage, ?Organisation $organisation, ?Model $subject, string $purpose): void
    {
        $rate = (string) config('auditor.claude_prices.usd_zar');

        foreach ($usage->counts as $count) {
            $usd = $this->usd($count);

            ClaudeUsageRecord::create([
                'organisation_id' => $organisation?->id,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'purpose' => $purpose,
                'model' => $count->model,
                'input_tokens' => $count->input,
                'output_tokens' => $count->output,
                'cache_read_tokens' => $count->cacheRead,
                'cache_write_tokens' => $count->cacheWrite,
                'cost_usd' => $usd !== null ? (string) $usd : null,
                'usd_zar_rate' => $rate,
                'cost_zar' => $usd !== null ? (string) $usd->multipliedBy($rate)->toScale(4, RoundingMode::HalfUp) : null,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * The dollar cost of one model's tokens, or null when the model has no
     * price in config.
     */
    public function usd(TokenCount $count): ?BigDecimal
    {
        $prices = self::pricesFor($count->model);

        if ($prices === null) {
            Log::warning('No price configured for a Claude model', ['model' => $count->model]);

            return null;
        }

        $input = Money::of($prices['input']);
        $cacheRead = Money::of($prices['cache_read'] ?? $prices['input']);
        $cacheWrite = $input->multipliedBy((string) config('auditor.claude_prices.cache_write_multiplier'));

        $total = Money::sum([
            $input->multipliedBy($count->input),
            Money::of($prices['output'])->multipliedBy($count->output),
            $cacheRead->multipliedBy($count->cacheRead),
            $cacheWrite->multipliedBy($count->cacheWrite),
        ]);

        return $total->dividedBy(1_000_000, 6, RoundingMode::HalfUp);
    }

    /**
     * Prices for a model id. Dated ids such as claude-opus-5-5-20260101 use
     * the prices of the model they belong to.
     *
     * @return array{input: string, output: string, cache_read: ?string}|null
     */
    private static function pricesFor(string $model): ?array
    {
        $models = config('auditor.claude_prices.models');

        foreach ($models as $id => $prices) {
            if ($model === $id || str_starts_with($model, $id.'-')) {
                return $prices;
            }
        }

        return null;
    }
}
