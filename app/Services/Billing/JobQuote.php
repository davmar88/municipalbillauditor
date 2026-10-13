<?php

namespace App\Services\Billing;

use App\Audit\Money;
use App\Enums\Job;

/**
 * What a job costs an organisation right now, shown before it runs.
 */
final readonly class JobQuote
{
    public function __construct(
        public Job $job,
        public string $price,
        public bool $coveredByPlan,
        public string $balance,
        public bool $affordable,
    ) {}

    /**
     * @return array{job: string, price: string, coveredByPlan: bool, balance: string, affordable: bool, shortfall: ?string}
     */
    public function toArray(): array
    {
        $shortfall = Money::of($this->price)->minus($this->balance);

        return [
            'job' => $this->job->value,
            'price' => $this->coveredByPlan ? 'Included' : Money::format($this->price),
            'coveredByPlan' => $this->coveredByPlan,
            'balance' => Money::format($this->balance),
            'affordable' => $this->affordable,
            'shortfall' => $shortfall->isPositive() ? Money::format($shortfall) : null,
        ];
    }
}
