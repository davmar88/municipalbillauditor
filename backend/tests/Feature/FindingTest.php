<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Finding;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingTest extends TestCase
{
    use RefreshDatabase;

    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->signIn();
        $property = Property::factory()->for($user)->create(['property_type' => 'residential']);
        $bills = $this->waterHistory($property, [10, 12, 11], ['consumption' => 20, 'reading_type' => 'estimated', 'tariff_category' => 'Business']);
        $this->bill = end($bills);
    }

    private function finding(string $rule): Finding
    {
        return $this->bill->findings()->where('rule', $rule)->firstOrFail();
    }

    public function test_dismiss_and_reopen(): void
    {
        $finding = $this->finding('estimated_reading');

        $this->patchJson("/api/v1/findings/{$finding->id}", ['status' => 'dismissed'])
            ->assertOk()
            ->assertJsonPath('data.id', $finding->id)
            ->assertJsonPath('data.status', 'dismissed');

        $this->getJson("/api/v1/bills/{$this->bill->id}")
            ->assertJsonPath('data.findings_summary', ['open_count' => 1, 'high_count' => 1, 'potential_overcharge_cents' => 0]);

        $this->patchJson("/api/v1/findings/{$finding->id}", ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_only_open_or_dismissed_can_be_set(): void
    {
        $finding = $this->finding('estimated_reading');

        $this->patchJson("/api/v1/findings/{$finding->id}", ['status' => 'disputed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->patchJson("/api/v1/findings/{$finding->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_a_disputed_finding_cannot_be_changed(): void
    {
        $finding = $this->finding('estimated_reading');
        $this->postJson("/api/v1/bills/{$this->bill->id}/disputes", ['finding_ids' => [$finding->id]])->assertCreated();

        $this->patchJson("/api/v1/findings/{$finding->id}", ['status' => 'dismissed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('disputed', $finding->fresh()->status->value);
    }

    public function test_reaudit_preserves_status_and_id_of_findings_that_still_match(): void
    {
        $estimated = $this->finding('estimated_reading');
        $tariff = $this->finding('tariff_mismatch');
        $this->patchJson("/api/v1/findings/{$tariff->id}", ['status' => 'dismissed'])->assertOk();
        $this->postJson("/api/v1/bills/{$this->bill->id}/disputes", ['finding_ids' => [$estimated->id]])->assertCreated();

        $this->postJson("/api/v1/bills/{$this->bill->id}/audit")->assertOk();

        // Editing the line items replaces them; the findings still match.
        $this->putJson("/api/v1/bills/{$this->bill->id}", ['line_items' => [[
            'service' => 'water', 'reading_type' => 'estimated', 'consumption' => 22, 'unit' => 'kl',
            'tariff_category' => 'Business', 'amount_cents' => 66000,
        ]]])->assertOk();

        $findings = collect($this->getJson("/api/v1/bills/{$this->bill->id}")->json('data.findings'))->keyBy('rule');
        $this->assertSame($tariff->id, $findings['tariff_mismatch']['id']);
        $this->assertSame('dismissed', $findings['tariff_mismatch']['status']);
        $this->assertSame($estimated->id, $findings['estimated_reading']['id']);
        $this->assertSame('disputed', $findings['estimated_reading']['status']);
        // Content is refreshed: (22 - 11) × 3000.
        $this->assertSame(33000, $findings['estimated_reading']['estimated_overcharge_cents']);
    }

    public function test_findings_that_no_longer_match_are_removed(): void
    {
        $this->putJson("/api/v1/bills/{$this->bill->id}", ['line_items' => [[
            'service' => 'water', 'reading_type' => 'actual', 'consumption' => 11, 'unit' => 'kl',
            'tariff_category' => 'Residential', 'amount_cents' => 33000,
        ]]])->assertOk()->assertJsonPath('data.findings', []);

        $this->assertSame(0, $this->bill->findings()->count());
    }

    public function test_a_disputed_finding_is_kept_when_a_reaudit_no_longer_raises_it(): void
    {
        $property = Property::factory()->for($this->bill->property->user)->create();
        $estimatedBill = fn (string $month) => $this->makeBill($property, [[
            'service' => 'water', 'reading_type' => 'estimated', 'consumption' => 12, 'unit' => 'kl', 'amount_cents' => 36000,
        ]], ['period_start' => "$month-01", 'period_end' => date('Y-m-t', strtotime("$month-01"))]);

        $estimatedBill('2026-01');
        $estimatedBill('2026-02');
        $march = $estimatedBill('2026-03');
        $finding = Finding::where('bill_id', $march->id)->where('rule', 'consecutive_estimates')->firstOrFail();

        $disputeId = $this->postJson("/api/v1/bills/{$march->id}/disputes", ['finding_ids' => [$finding->id]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/disputes/{$disputeId}/submit", ['channel' => 'email'])->assertOk();

        // The next bill is estimated too, so the rule now fires on April only.
        $april = $estimatedBill('2026-04');
        $this->assertTrue(Finding::where('bill_id', $april->id)->where('rule', 'consecutive_estimates')->exists());

        // March keeps the finding you disputed, unchanged.
        $marchFindings = collect($this->getJson("/api/v1/bills/{$march->id}")->json('data.findings'))->keyBy('rule');
        $this->assertSame($finding->id, $marchFindings['consecutive_estimates']['id']);
        $this->assertSame('disputed', $marchFindings['consecutive_estimates']['status']);
        $this->assertSame(3, $marchFindings['consecutive_estimates']['evidence']['consecutive_count']);

        $dispute = $this->getJson("/api/v1/disputes/{$disputeId}")->json('data');
        $this->assertSame([$finding->id], $dispute['finding_ids']);
        $this->assertSame(1, Finding::whereIn('id', $dispute['finding_ids'])->count());

        // Re-auditing March directly doesn't remove it either.
        $this->postJson("/api/v1/bills/{$march->id}/audit")->assertOk()
            ->assertJsonPath('data.findings.0.id', $finding->id);
    }

    public function test_deleting_a_draft_dispute_clears_findings_that_no_longer_apply(): void
    {
        $estimated = $this->finding('estimated_reading');
        $tariff = $this->finding('tariff_mismatch');
        $disputeId = $this->postJson("/api/v1/bills/{$this->bill->id}/disputes", ['finding_ids' => [$estimated->id, $tariff->id]])
            ->assertCreated()->json('data.id');

        // You correct the tariff on the bill: the tariff rule stops firing, but
        // the finding stays while the dispute names it.
        $this->putJson("/api/v1/bills/{$this->bill->id}", ['line_items' => [[
            'service' => 'water', 'reading_type' => 'estimated', 'consumption' => 20, 'unit' => 'kl',
            'tariff_category' => 'Residential', 'amount_cents' => 60000,
        ]]])->assertOk();
        $this->assertSame('disputed', $tariff->fresh()->status->value);

        // Without the dispute, the stale finding goes and the other reopens.
        $this->deleteJson("/api/v1/disputes/{$disputeId}")->assertNoContent();

        $this->assertNull($tariff->fresh());
        $this->assertSame('open', $estimated->fresh()->status->value);
        $this->getJson("/api/v1/bills/{$this->bill->id}")
            ->assertJsonPath('data.findings_summary.open_count', 1)
            ->assertJsonPath('data.findings.0.rule', 'estimated_reading');
    }

    public function test_consecutive_estimates_move_to_the_newest_bill_when_another_estimate_arrives(): void
    {
        $property = Property::factory()->for($this->bill->property->user)->create();
        $bills = [];
        foreach (['2026-01', '2026-02', '2026-03', '2026-04'] as $month) {
            $bills[] = $this->makeBill($property, [[
                'service' => 'electricity', 'reading_type' => 'estimated', 'consumption' => 300, 'unit' => 'kwh', 'amount_cents' => 90000,
            ]], ['period_start' => "$month-01", 'period_end' => date('Y-m-t', strtotime("$month-01"))]);

            $holders = collect($bills)
                ->filter(fn (Bill $b) => $b->findings()->where('rule', 'consecutive_estimates')->exists())
                ->map(fn (Bill $b) => $b->id)
                ->values()
                ->all();
            $this->assertSame(count($bills) >= 3 ? [end($bills)->id] : [], $holders);
        }

        $this->assertSame(4, Finding::where('bill_id', $bills[3]->id)->where('rule', 'consecutive_estimates')->first()->evidence['consecutive_count']);
    }
}
