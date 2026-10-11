<?php

namespace App\Models;

use App\Audit\Enums\TariffCategory;
use App\Enums\BillSource;
use App\Enums\BillStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One municipal bill. The file is personal financial data, so it lives on
 * the private disk and is only ever streamed to its owner.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int|null $property_id
 * @property int|null $user_id
 * @property BillSource $source
 * @property BillStatus $status
 * @property bool $needs_review
 * @property string|null $review_note
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $file_mime
 * @property int|null $file_size
 * @property CarbonImmutable|null $bill_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property string|null $account_number
 * @property string|null $tariff_label
 * @property TariffCategory $tariff_category
 * @property string|null $total_due
 * @property string|null $read_method
 * @property array<string, mixed>|null $extraction
 * @property list<string>|null $passed
 * @property list<string>|null $skipped
 * @property string|null $meter_photo_path
 * @property Carbon|null $checked_at
 */
#[Fillable([
    'property_id', 'user_id', 'source', 'status', 'needs_review', 'review_note',
    'file_path', 'file_name', 'file_mime', 'file_size',
    'bill_date', 'due_date', 'period_start', 'period_end', 'account_number',
    'tariff_label', 'tariff_category', 'total_due', 'read_method', 'extraction',
    'passed', 'skipped', 'meter_photo_path', 'checked_at',
])]
class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Deleting a bill deletes its files too (POPIA: nothing left behind).
        static::deleting(function (Bill $bill) {
            $disk = Storage::disk(config('auditor.bill_disk'));

            foreach (array_filter([$bill->file_path, $bill->meter_photo_path]) as $path) {
                $disk->delete($path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'source' => BillSource::class,
            'status' => BillStatus::class,
            'needs_review' => 'boolean',
            'bill_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'tariff_category' => TariffCategory::class,
            'total_due' => 'decimal:2',
            'extraction' => 'array',
            'passed' => 'array',
            'skipped' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * The person who sent the bill in, when it came from a person.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return HasMany<MeterReading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /** @return HasMany<Charge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /** @return HasMany<Finding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class)->orderBy('number');
    }

    /** @return HasMany<Dispute, $this> */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /** @return HasOne<Dispute, $this> */
    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class)->latestOfMany();
    }

    /** A bill whose dispute has been sent can't be edited or re-checked. */
    public function isLocked(): bool
    {
        return $this->disputes()->whereNotNull('submitted_on')->exists();
    }

    /** The month the bill is for: the end of its period, else its date. */
    public function month(): ?CarbonImmutable
    {
        return $this->period_end ?? $this->bill_date;
    }

    public function monthLabel(): string
    {
        return $this->month()?->format('F Y') ?? 'Bill';
    }

    public function isChecked(): bool
    {
        return $this->status === BillStatus::Checked;
    }
}
