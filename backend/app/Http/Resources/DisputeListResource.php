<?php

namespace App\Http\Resources;

/**
 * Collection shape: letter_body and events are omitted, and account numbers
 * in letter_subject are masked.
 */
class DisputeListResource extends DisputeResource
{
    protected bool $withDetails = false;
}
