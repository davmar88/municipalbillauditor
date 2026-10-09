<?php

namespace App\Http\Resources;

/**
 * List shape: only the masked account number (least exposure).
 */
class PropertyListResource extends PropertyResource
{
    protected bool $withAccountNumber = false;
}
