<?php

namespace App\Models;

use App\Audit\Deadlines\MetroRules;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A municipality we write disputes for, with its rules.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $short_name
 * @property string|null $area_name
 * @property string $billing_addressee
 * @property string|null $billing_email
 * @property int $dispute_window_days
 * @property int $reply_window_days
 * @property int $councillor_window_days
 * @property bool $rules_verified
 * @property string|null $rules_notes
 * @property string|null $letter_paragraph
 * @property bool $live
 */
#[Fillable([
    'slug', 'name', 'short_name', 'area_name', 'billing_addressee', 'billing_email',
    'dispute_window_days', 'reply_window_days', 'councillor_window_days',
    'rules_verified', 'rules_notes', 'letter_paragraph', 'live', 'sort',
])]
class Metro extends Model
{
    protected function casts(): array
    {
        return [
            'rules_verified' => 'boolean',
            'live' => 'boolean',
            'dispute_window_days' => 'integer',
            'reply_window_days' => 'integer',
            'councillor_window_days' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /** @return HasMany<Outage, $this> */
    public function outages(): HasMany
    {
        return $this->hasMany(Outage::class);
    }

    /** @return HasMany<TariffRate, $this> */
    public function tariffRates(): HasMany
    {
        return $this->hasMany(TariffRate::class);
    }

    public function rules(): MetroRules
    {
        return new MetroRules($this->dispute_window_days, $this->reply_window_days, $this->councillor_window_days);
    }

    /** "Tshwane (Pretoria)" */
    public function displayName(): string
    {
        return $this->area_name ? "{$this->short_name} ({$this->area_name})" : $this->short_name;
    }
}
