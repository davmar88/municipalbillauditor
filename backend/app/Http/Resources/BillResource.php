<?php

namespace App\Http\Resources;

use App\Audit\OverchargeTotals;
use App\Models\Bill;
use App\Models\Finding;
use Illuminate\Http\Request;

/**
 * Single-bill shape: includes line items and findings.
 *
 * @mixin Bill
 */
class BillResource extends ApiResource
{
    protected bool $withDetails = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $findings = $this->findings
            ->sortBy(fn (Finding $f) => [-$f->severity->rank(), $f->id])
            ->values();

        $counted = $findings->filter(fn (Finding $f) => $f->counts());

        $data = [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'bill_date' => $this->bill_date?->toDateString(),
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'total_cents' => $this->total_cents,
            'status' => $this->status->value,
            'extraction_source' => $this->extraction_source->value,
            'has_file' => $this->hasFile(),
            'file_mime' => $this->file_mime,
            'dispute_deadline' => $this->disputeDeadline()?->toDateString(),
            'findings_summary' => [
                'open_count' => $counted->count(),
                'high_count' => $counted->filter(fn (Finding $f) => $f->severity->value === 'high')->count(),
                'potential_overcharge_cents' => OverchargeTotals::sum($counted),
            ],
        ];

        if ($this->withDetails) {
            $data['line_items'] = LineItemResource::collection($this->lineItems)->resolve($request);
            $data['findings'] = FindingResource::collection($findings)->resolve($request);
        }

        $data['created_at'] = $this->created_at?->toISOString();

        return $data;
    }
}
