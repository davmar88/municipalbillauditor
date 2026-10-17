<?php

namespace App\Game\Translation;

interface Translator
{
    public function available(): bool;

    /**
     * The message in the given language, or null when it couldn't be
     * translated.
     */
    public function translate(string $text, string $language): ?string;
}
