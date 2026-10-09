<?php

namespace App\Models;

use App\Enums\FindingRule;
use App\Enums\FindingStatus;
use App\Enums\Service;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bill_id
 * @property int|null $line_item_id
 * @property FindingRule $rule
 * @property Service|null $service
 * @property string $match_key
 * @property Severity $severity
 * @property float $confidence
 * @property string $title
 * @property string $explanation
 * @property int|null $estimated_overcharge_cents
 * @property array<string, mixed> $evidence
 * @property FindingStatus $status
 * @property-read Bill $bill
 */
#[Fillable(['line_item_id', 'rule', 'service', 'match_key', 'severity', 'confidence', 'title', 'explanation', 'estimated_overcharge_cents', 'evidence', 'status'])]
class Finding extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule' => FindingRule::class,
            'service' => Service::class,
            'severity' => Severity::class,
            'status' => FindingStatus::class,
            'confidence' => 'float',
            'estimated_overcharge_cents' => 'integer',
            'evidence' => 'array',
        ];
    }

    /**
     * Keep whole-number decimals in evidence as decimals (38.0, not 38).
     *
     * @param  string  $key
     */
    protected function getJsonCastFlags($key): int
    {
        return parent::getJsonCastFlags($key) | JSON_PRESERVE_ZERO_FRACTION;
    }

    /**
     * @param  Builder<Finding>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->whereIn('bill_id', Bill::query()->select('id')->ownedBy($user));
    }

    /**
     * Open medium/high findings: the ones that count towards dashboards,
     * deadlines and potential overcharge totals.
     *
     * @param  Builder<Finding>  $query
     */
    public function scopeOpenAndCounted(Builder $query): void
    {
        $query->where('status', FindingStatus::Open->value)->whereIn('severity', Severity::counted());
    }

    public function counts(): bool
    {
        return $this->status === FindingStatus::Open && in_array($this->severity->value, Severity::counted(), true);
    }

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * @return BelongsTo<LineItem, $this>
     */
    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(LineItem::class);
    }
}
