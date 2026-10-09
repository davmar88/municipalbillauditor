<?php

namespace App\Audit;

use App\Audit\Rules\AuditRule;
use App\Enums\BillStatus;
use App\Enums\FindingRule;
use App\Enums\FindingStatus;
use App\Models\Bill;
use App\Models\Finding;
use App\Models\Outage;
use App\Models\Property;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Runs every rule over a bill and rebuilds its findings. Findings that still
 * match (same rule + same service) keep their id and status, so a dismissed
 * finding stays dismissed and a disputed one stays disputed. A disputed
 * finding is never removed, even when its rule no longer fires: it is the
 * record of what you formally disputed.
 */
class AuditService
{
    /** @var array<string, AuditRule> */
    private array $rules = [];

    /**
     * @param  iterable<AuditRule>  $rules
     */
    public function __construct(iterable $rules)
    {
        foreach ($rules as $rule) {
            $this->rules[$rule->rule()->value] = $rule;
        }
    }

    /**
     * @return array<string, AuditRule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function ruleFor(FindingRule $rule): AuditRule
    {
        return $this->rules[$rule->value] ?? throw new InvalidArgumentException("No audit rule for [{$rule->value}].");
    }

    /**
     * Audits one bill against its property's other bills and outages.
     */
    public function audit(Bill $bill): Bill
    {
        $property = $bill->property()->firstOrFail();
        [$bills, $outages] = $this->loadPropertyData($property);

        $fresh = $bills->firstWhere('id', $bill->id) ?? $bill->load('lineItems');
        $this->auditWith($fresh, $property, $bills, $outages);

        return $bill->refresh();
    }

    /**
     * Re-audits every audited bill of a property, optionally limited by a
     * filter (e.g. only bills whose period overlaps an outage).
     *
     * @param  (callable(Bill): bool)|null  $filter
     */
    public function auditProperty(Property $property, ?callable $filter = null): void
    {
        [$bills, $outages] = $this->loadPropertyData($property);

        foreach ($bills as $bill) {
            if ($bill->status !== BillStatus::Audited || $bill->lineItems->isEmpty()) {
                continue;
            }
            if ($filter !== null && ! $filter($bill)) {
                continue;
            }
            $this->auditWith($bill, $property, $bills, $outages);
        }
    }

    /**
     * Runs the rules without storing anything.
     *
     * @return list<FindingData>
     */
    public function evaluate(AuditContext $context): array
    {
        $results = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->evaluate($context) as $finding) {
                // One finding per rule + service.
                $results[$finding->matchKey()] = $finding;
            }
        }

        return array_values($results);
    }

    /**
     * @param  Collection<int, Bill>  $bills
     * @param  Collection<int, Outage>  $outages
     */
    private function auditWith(Bill $bill, Property $property, Collection $bills, Collection $outages): void
    {
        $bill->setRelation('property', $property);

        if ($bill->lineItems->isEmpty()) {
            // Nothing to audit until line items are entered.
            return;
        }

        $context = AuditContext::build($bill, $property, $bills, $outages);
        $results = $this->evaluate($context);

        DB::transaction(function () use ($bill, $results) {
            $existing = Finding::query()->where('bill_id', $bill->id)->get()->keyBy('match_key');
            $keep = [];

            foreach ($results as $data) {
                /** @var Finding $finding */
                $finding = $existing->get($data->matchKey()) ?? new Finding([
                    'match_key' => $data->matchKey(),
                    'status' => FindingStatus::Open,
                ]);

                $finding->bill_id = $bill->id;
                $finding->fill([
                    'line_item_id' => $data->lineItemId,
                    'rule' => $data->rule,
                    'service' => $data->service,
                    'severity' => $data->severity,
                    'confidence' => $data->confidence,
                    'title' => $data->title,
                    'explanation' => $data->explanation,
                    'estimated_overcharge_cents' => $data->estimatedOverchargeCents,
                    'evidence' => $data->evidence,
                ]);
                $finding->save();
                $keep[] = $finding->id;
            }

            // Findings that no longer match go, except disputed ones: their
            // dispute points at them, and the bill should still show what you
            // disputed. They become ordinary again if the dispute is deleted
            // while it is a draft (DisputeService::delete re-audits the bill).
            Finding::query()
                ->where('bill_id', $bill->id)
                ->whereNotIn('id', $keep)
                ->where('status', '!=', FindingStatus::Disputed->value)
                ->delete();

            if ($bill->status !== BillStatus::Audited) {
                $bill->status = BillStatus::Audited;
                $bill->save();
            }
        });
    }

    /**
     * @return array{0: Collection<int, Bill>, 1: Collection<int, Outage>}
     */
    private function loadPropertyData(Property $property): array
    {
        $bills = $property->bills()->with('lineItems')->get();
        $bills->each(fn (Bill $b) => $b->setRelation('property', $property));
        $outages = $property->outages()->get();

        return [$bills, $outages];
    }
}
