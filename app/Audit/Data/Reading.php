<?php

namespace App\Audit\Data;

use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use Brick\Math\BigDecimal;

final readonly class Reading
{
    public function __construct(
        public Service $service,
        public ?BigDecimal $consumption,
        public ReadingType $type = ReadingType::Unknown,
        public string $unit = '',
        public string $meterNumber = '',
        public ?BigDecimal $previous = null,
        public ?BigDecimal $current = null,
    ) {}

    /** What was used: the stated consumption, else current minus previous. */
    public function used(): ?BigDecimal
    {
        if ($this->consumption !== null) {
            return $this->consumption;
        }

        if ($this->previous !== null && $this->current !== null && $this->current->isGreaterThanOrEqualTo($this->previous)) {
            return $this->current->minus($this->previous);
        }

        return null;
    }

    public function unitLabel(): string
    {
        return $this->unit !== '' ? $this->unit : $this->service->defaultUnit();
    }
}
