<?php

namespace App\Audit\Data;

use App\Audit\Enums\CheckType;
use App\Audit\Enums\Confidence;
use App\Audit\Enums\Service;
use Brick\Math\BigDecimal;

/**
 * One possible error, with the figures behind it.
 */
final readonly class Finding
{
    /**
     * @param  list<array{0: string, 1: string}>  $evidence  Label and value pairs, in display order.
     * @param  list<array{0: string, 1: string}>  $answerOptions  Value and label pairs for the question.
     * @param  array<string, mixed>  $data  Numbers the letter and claim screens reuse, JSON-safe.
     */
    public function __construct(
        public CheckType $check,
        public ?Service $service,
        public Confidence $confidence,
        public string $title,
        public string $explanation,
        public ?BigDecimal $amount = null,
        public bool $amountIsUpperBound = false,
        public array $evidence = [],
        public string $calculation = '',
        public string $question = '',
        public array $answerOptions = [],
        public string $action = '',
        public array $data = [],
    ) {}
}
