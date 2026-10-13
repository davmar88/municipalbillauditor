<?php

namespace App\Audit\Letters;

use App\Audit\Enums\CheckType;
use App\Audit\Enums\Service;
use Brick\Math\BigDecimal;

final readonly class LetterItem
{
    /**
     * @param  array<string, mixed>  $data  The finding's data.
     * @param  string  $answer  The customer's answer, if the finding asked a question.
     */
    public function __construct(
        public CheckType $check,
        public ?Service $service,
        public ?BigDecimal $amount,
        public array $data = [],
        public string $answer = '',
    ) {}
}
