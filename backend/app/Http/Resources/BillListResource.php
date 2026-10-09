<?php

namespace App\Http\Resources;

/**
 * Collection shape: line_items and findings are omitted.
 */
class BillListResource extends BillResource
{
    protected bool $withDetails = false;
}
