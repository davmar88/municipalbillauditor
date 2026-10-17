<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Four weeks (two in the test) of politics on one valley. The map, the
 * laws and the treasuries reset when it ends.
 *
 * @property int $id
 * @property int $valley_id
 * @property int $number
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $ended_at
 * @property int $boots
 * @property int $bonus_law_changes
 * @property Carbon|null $house_since
 * @property Carbon|null $last_secret_edit_at
 * @property Carbon|null $last_festival_at
 * @property int $tax_collected Grain tax since the last rations were handed out.
 * @property Carbon|null $last_rations_on
 * @property Carbon|null $last_gate_night_on
 * @property Valley $valley
 */
#[Fillable(['valley_id', 'number', 'starts_at', 'ends_at'])]
class Season extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'house_since' => 'datetime',
            'last_secret_edit_at' => 'datetime',
            'last_festival_at' => 'datetime',
            'last_rations_on' => 'date',
            'last_gate_night_on' => 'date',
        ];
    }

    /** @return BelongsTo<Valley, $this> */
    public function valley(): BelongsTo
    {
        return $this->belongsTo(Valley::class);
    }

    /** @return HasMany<Law, $this> */
    public function laws(): HasMany
    {
        return $this->hasMany(Law::class);
    }

    /** @return HasMany<LawVersion, $this> */
    public function lawVersions(): HasMany
    {
        return $this->hasMany(LawVersion::class);
    }

    /** @return HasMany<UnrestEvent, $this> */
    public function unrestEvents(): HasMany
    {
        return $this->hasMany(UnrestEvent::class);
    }

    /** @return HasMany<Attack, $this> */
    public function attacks(): HasMany
    {
        return $this->hasMany(Attack::class);
    }

    /** @return HasMany<Uprising, $this> */
    public function uprisings(): HasMany
    {
        return $this->hasMany(Uprising::class);
    }

    /** @return HasMany<Crime, $this> */
    public function crimes(): HasMany
    {
        return $this->hasMany(Crime::class);
    }
}
