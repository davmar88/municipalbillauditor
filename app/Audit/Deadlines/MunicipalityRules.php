<?php

namespace App\Audit\Deadlines;

/**
 * One metro's dispute rules.
 *
 * The defaults are placeholders until each metro's rules are researched: the
 * 30-day dispute window comes from an eThekwini councillor's advice in the
 * founder's notes, and the reply and councillor windows are guesses. Staff
 * set them per metro.
 */
final readonly class MunicipalityRules
{
    public function __construct(
        public int $disputeWindowDays = 30,
        public int $replyWindowDays = 21,
        public int $councillorWindowDays = 30,
    ) {}
}
