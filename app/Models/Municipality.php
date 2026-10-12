<?php

namespace App\Models;

use App\Audit\Deadlines\MunicipalityRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of South Africa's 257 municipalities, with its dispute rules and the
 * addresses disputes go to.
 *
 * @property int $id
 * @property string|null $code
 * @property string $slug
 * @property string $name
 * @property string $short_name
 * @property string $category
 * @property string|null $province
 * @property string|null $district_code
 * @property string|null $seat
 * @property string|null $area_name
 * @property string $billing_addressee
 * @property string|null $billing_email
 * @property string|null $billing_email_source
 * @property CarbonImmutable|null $billing_email_verified_at
 * @property string|null $escalation_email
 * @property string|null $escalation_email_source
 * @property CarbonImmutable|null $escalation_email_verified_at
 * @property list<string>|null $aliases
 * @property int $dispute_window_days
 * @property int $reply_window_days
 * @property int $councillor_window_days
 * @property bool $rules_verified
 * @property string|null $rules_notes
 * @property string|null $letter_paragraph
 * @property bool $live
 * @property bool $has_landing_page
 */
#[Fillable([
    'code', 'slug', 'name', 'short_name', 'category', 'province', 'district_code', 'seat', 'area_name',
    'billing_addressee', 'billing_email', 'billing_email_source', 'billing_email_verified_at',
    'escalation_email', 'escalation_email_source', 'escalation_email_verified_at', 'aliases',
    'dispute_window_days', 'reply_window_days', 'councillor_window_days',
    'rules_verified', 'rules_notes', 'letter_paragraph', 'live', 'has_landing_page', 'sort',
])]
class Municipality extends Model
{
    public const CATEGORIES = ['A' => 'Metropolitan', 'B' => 'Local', 'C' => 'District'];

    protected function casts(): array
    {
        return [
            'rules_verified' => 'boolean',
            'live' => 'boolean',
            'has_landing_page' => 'boolean',
            'dispute_window_days' => 'integer',
            'reply_window_days' => 'integer',
            'councillor_window_days' => 'integer',
            'billing_email_verified_at' => 'immutable_datetime',
            'escalation_email_verified_at' => 'immutable_datetime',
            'aliases' => 'array',
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

    public function rules(): MunicipalityRules
    {
        return new MunicipalityRules($this->dispute_window_days, $this->reply_window_days, $this->councillor_window_days);
    }

    /**
     * The rules for a property's municipality, or the placeholder defaults
     * while we don't know it yet.
     */
    public static function rulesFor(?self $municipality): MunicipalityRules
    {
        return $municipality?->rules() ?? new MunicipalityRules;
    }

    /** "Tshwane (Pretoria)" */
    public function displayName(): string
    {
        return $this->area_name ? "{$this->short_name} ({$this->area_name})" : $this->short_name;
    }

    /** "Stellenbosch, Western Cape", for picking from the full list. */
    public function optionLabel(): string
    {
        return $this->province ? "{$this->displayName()}, {$this->province}" : $this->displayName();
    }

    /**
     * Every municipality we accept, for a picker grouped by province.
     *
     * @return list<array{id: int, name: string, province: ?string}>
     */
    public static function options(): array
    {
        return self::query()
            ->where('live', true)
            ->orderBy('province')
            ->orderBy('short_name')
            ->get()
            ->map(fn (self $m) => ['id' => $m->id, 'name' => $m->displayName(), 'province' => $m->province])
            ->values()
            ->all();
    }

    /**
     * The metros with their own landing page, in menu order.
     *
     * @return Collection<int, self>
     */
    public static function landingPages(): Collection
    {
        return self::query()->where('live', true)->where('has_landing_page', true)->orderBy('sort')->get();
    }

    public function isMetro(): bool
    {
        return $this->category === 'A';
    }
}
