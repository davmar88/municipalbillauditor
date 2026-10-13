<?php

namespace App\Models;

use App\Audit\Deadlines\MunicipalityRules;
use App\Audit\Enums\PropertyType;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A municipal account at an address: a home, a rental unit or a scheme.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int|null $municipality_id
 * @property string $name
 * @property string|null $unit
 * @property string|null $address
 * @property string|null $suburb
 * @property PropertyType $property_type
 * @property string|null $account_number
 */
#[Fillable(['municipality_id', 'name', 'unit', 'address', 'suburb', 'property_type', 'account_number'])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['property_type' => PropertyType::class];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Municipality, $this> */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /** @return HasMany<Bill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /** @return HasOne<Bill, $this> */
    public function latestBill(): HasOne
    {
        return $this->hasOne(Bill::class)->ofMany(['bill_date' => 'max', 'id' => 'max']);
    }

    /** @return HasMany<Dispute, $this> */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /** The dispute rules that apply here: placeholders until we know the municipality. */
    public function rules(): MunicipalityRules
    {
        return Municipality::rulesFor($this->municipality);
    }

    /** "Jacaranda Court · 12" */
    public function label(): string
    {
        return $this->unit ? "{$this->name} · {$this->unit}" : $this->name;
    }
}
