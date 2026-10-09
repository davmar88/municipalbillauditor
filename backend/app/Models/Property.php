<?php

namespace App\Models;

use App\Enums\FindingStatus;
use App\Enums\PropertyType;
use App\Enums\Severity;
use App\Support\Metros;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property int $id
 * @property int $user_id
 * @property string $nickname
 * @property string $metro
 * @property string $account_number
 * @property string $address
 * @property PropertyType $property_type
 */
#[Fillable(['nickname', 'metro', 'account_number', 'address', 'property_type'])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // POPIA: encrypted at rest.
            'account_number' => 'encrypted',
            'address' => 'encrypted',
            'property_type' => PropertyType::class,
        ];
    }

    /**
     * @param  Builder<Property>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Adds bills_count and open_findings_count (open medium/high findings).
     *
     * @param  Builder<Property>  $query
     */
    public function scopeWithSummaryCounts(Builder $query): void
    {
        $query->withCount([
            'bills',
            'findings as open_findings_count' => fn (Builder $q) => $q
                ->where('findings.status', FindingStatus::Open->value)
                ->whereIn('findings.severity', Severity::counted()),
        ]);
    }

    public function loadSummaryCounts(): static
    {
        return $this->loadCount([
            'bills',
            'findings as open_findings_count' => fn (Builder $q) => $q
                ->where('findings.status', FindingStatus::Open->value)
                ->whereIn('findings.severity', Severity::counted()),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * @return HasMany<Outage, $this>
     */
    public function outages(): HasMany
    {
        return $this->hasMany(Outage::class);
    }

    /**
     * @return HasManyThrough<Finding, Bill, $this>
     */
    public function findings(): HasManyThrough
    {
        return $this->hasManyThrough(Finding::class, Bill::class);
    }

    /**
     * @return HasManyThrough<Dispute, Bill, $this>
     */
    public function disputes(): HasManyThrough
    {
        return $this->hasManyThrough(Dispute::class, Bill::class);
    }

    /**
     * The metro's configuration (dispute window, steps, ...).
     *
     * @return array<string, mixed>
     */
    public function metroConfig(): array
    {
        return Metros::find($this->metro);
    }

    /**
     * "••••4567": only the last four characters are shown.
     */
    public function maskedAccountNumber(): string
    {
        $compact = preg_replace('/\s+/', '', (string) $this->account_number) ?? '';

        return '••••'.mb_substr($compact, -4);
    }
}
