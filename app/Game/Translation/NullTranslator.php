<?php

namespace App\Game\Translation;

/** Used when no API key is set: the Translate button says it isn't available. */
class NullTranslator implements Translator
{
    public function available(): bool
    {
        return false;
    }

    public function translate(string $text, string $language): ?string
    {
        return null;
    }
}
