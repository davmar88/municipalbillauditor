<?php

namespace App\Models;

use App\Game\LawTemplate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One of the five laws painted on the Barn Wall right now.
 *
 * @property int $id
 * @property int $season_id
 * @property int $slot
 * @property LawTemplate $template
 * @property int|null $value
 * @property string|null $target
 * @property Carbon|null $expires_at
 */
#[Fillable(['season_id', 'slot', 'template', 'value', 'target', 'expires_at'])]
class Law extends Model
{
    protected function casts(): array
    {
        return [
            'template' => LawTemplate::class,
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function wording(): string
    {
        return $this->template->wording($this->value, $this->target);
    }

    public function unrest(): int
    {
        return $this->template->unrest($this->value, $this->target);
    }
}
