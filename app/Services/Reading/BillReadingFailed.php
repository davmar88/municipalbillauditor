<?php

namespace App\Services\Reading;

use App\Services\Claude\ClaudeUsage;
use RuntimeException;

/**
 * The bill couldn't be read automatically. The message is safe to show the
 * customer; the bill waits for someone to type in the details.
 */
class BillReadingFailed extends RuntimeException
{
    /** What the Claude call used, when one was made: a failed read still costs. */
    public ?ClaudeUsage $usage = null;

    public function withUsage(?ClaudeUsage $usage): static
    {
        $this->usage = $usage;

        return $this;
    }
}
