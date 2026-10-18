<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A setting the owner typed in on the admin page, such as a payment key.
 * Every value is encrypted with the app key, so a copy of the database
 * alone gives nobody the keys.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property int|null $updated_by
 */
#[Fillable(['key', 'value', 'updated_by'])]
class AppSetting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }
}
