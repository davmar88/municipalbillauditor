<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base resource: decimals stay decimals in JSON ("consumption": 38.0, as in
 * docs/api.md) and text such as the masked account number is not
 * \u-escaped.
 */
abstract class ApiResource extends JsonResource
{
    public const JSON_OPTIONS = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @return int
     */
    public function jsonOptions()
    {
        return self::JSON_OPTIONS;
    }
}
