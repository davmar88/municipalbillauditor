<?php

namespace App\Services\Claude;

/**
 * The tokens one model used on one call.
 */
final readonly class TokenCount
{
    public function __construct(
        public string $model,
        public int $input,
        public int $output,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
    ) {}
}
