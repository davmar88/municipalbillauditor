<?php

namespace App\Services\Replies;

interface ReplyReader
{
    /**
     * Read a municipality's reply to a dispute.
     *
     * @param  string  $context  What the dispute is about, so the reply can be matched to it.
     */
    public function read(string $context, string $subject, string $body): ReplyReading;
}
