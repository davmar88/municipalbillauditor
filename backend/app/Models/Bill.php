<?php

namespace App\Models;

use App\Enums\BillStatus;
use App\Enums\ExtractionSource;
use Database\Factories\BillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $property_id
 * @property Carbon|null $bill_date
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $due_date
 * @property int|null $total_cents
 * @property BillStatus $status
 * @property ExtractionSource $extraction_source
 * @property string|null $file_path
 * @property string|null $file_mime
 * @property-read Property $property
 */
#[Fillable(['bill_date', 'period_start', 'period_end', 'due_date', 'total_cents', 'status', 'extraction_source'])]
class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'total_cents' => 'integer',
            'status' => BillStatus::class,
            'extraction_source' => ExtractionSource::class,
        ];
    }

    /**
     * @param  Builder<Bill>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->whereIn('property_id', Property::query()->select('id')->where('user_id', $user->id));
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<LineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(LineItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /**
     * @return HasMany<Dispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null;
    }

    /**
     * bill_date + the metro's dispute window; null when bill_date is null.
     */
    public function disputeDeadline(): ?Carbon
    {
        if ($this->bill_date === null) {
            return null;
        }

        return $this->bill_date->copy()->addDays((int) $this->property->metroConfig()['dispute_window_days']);
    }
}
