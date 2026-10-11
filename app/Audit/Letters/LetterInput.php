<?php

namespace App\Audit\Letters;

use App\Audit\Enums\PropertyType;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

final readonly class LetterInput
{
    /**
     * @param  list<LetterItem>  $items
     */
    public function __construct(
        public string $addressee,
        public string $senderName,
        public string $accountNumber,
        public string $propertyAddress,
        public ?DateTimeImmutable $billDate,
        public ?DateTimeImmutable $billMonth,
        public ?BigDecimal $totalDue,
        public array $items,
        public DateTimeImmutable $letterDate,
        public PropertyType $propertyType = PropertyType::Unknown,
        public bool $hasMeterPhoto = false,
        public string $extraParagraph = '',
    ) {}
}
