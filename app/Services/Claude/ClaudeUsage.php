<?php

namespace App\Services\Claude;

/**
 * What one Messages API call used, per model. A call usually runs on one
 * model; with server-side fallbacks on, a declined attempt and the fallback
 * model's answer are both billed, so both are counted.
 */
final readonly class ClaudeUsage
{
    /**
     * @param  list<TokenCount>  $counts
     */
    public function __construct(public array $counts) {}

    /**
     * Read the usage off a Messages API reply.
     *
     * @param  object  $message  The SDK's message, with model and usage.
     */
    public static function fromMessage(object $message, string $requestedModel): self
    {
        $usage = $message->usage ?? null;

        if ($usage === null) {
            return new self([]);
        }

        $iterations = array_values(array_filter(
            (array) ($usage->iterations ?? []),
            fn ($item) => is_object($item) && in_array($item->type ?? null, ['message', 'fallback_message'], true),
        ));

        if ($iterations !== []) {
            return new self(array_map(fn (object $item) => new TokenCount(
                model: self::model($item->model ?? null) ?? $requestedModel,
                input: (int) ($item->inputTokens ?? 0),
                output: (int) ($item->outputTokens ?? 0),
                cacheRead: (int) ($item->cacheReadInputTokens ?? 0),
                cacheWrite: (int) ($item->cacheCreationInputTokens ?? 0),
            ), $iterations));
        }

        return new self([new TokenCount(
            model: self::model($message->model ?? null) ?? $requestedModel,
            input: (int) ($usage->inputTokens ?? 0),
            output: (int) ($usage->outputTokens ?? 0),
            cacheRead: (int) ($usage->cacheReadInputTokens ?? 0),
            cacheWrite: (int) ($usage->cacheCreationInputTokens ?? 0),
        )]);
    }

    public function isEmpty(): bool
    {
        return $this->counts === [];
    }

    private static function model(mixed $model): ?string
    {
        if ($model instanceof \BackedEnum) {
            return (string) $model->value;
        }

        return is_string($model) && $model !== '' ? $model : null;
    }
}
