<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-09T08:00:00Z'));
        $this->user = $this->signIn();
    }

    private function billWithFindings(Property $property, string $billDate, array $extra = []): Bill
    {
        // A medium estimated-reading finding (R270.00) on the newest bill.
        $history = [10, 12, 11];
        $bills = [];
        foreach ([...$history, 20] as $i => $kl) {
            $bills[] = $this->makeBill($property, [[
                'service' => 'water', 'unit' => 'kl', 'consumption' => $kl, 'amount_cents' => $kl * 3000,
                'reading_type' => $i === 3 ? 'estimated' : 'actual', ...($i === 3 ? $extra : []),
            ]], ['bill_date' => CarbonImmutable::parse($billDate)->subMonths(3 - $i)->toDateString()]);
        }

        return end($bills);
    }

    public function test_empty_dashboard(): void
    {
        $this->getJson('/api/v1/dashboard')->assertOk()->assertExactJson(['data' => [
            'properties_count' => 0,
            'open_findings_count' => 0,
            'potential_overcharge_cents' => 0,
            'active_disputes_count' => 0,
            'recovered_cents' => 0,
            'upcoming_deadlines' => [],
        ]]);
    }

    public function test_counts_and_deadlines(): void
    {
        $sunset = Property::factory()->for($this->user)->create(['nickname' => 'Sunset Court', 'property_type' => 'residential']);
        $flat = Property::factory()->for($this->user)->create(['nickname' => 'Flat 4', 'property_type' => 'residential', 'metro' => 'tshwane']);

        $billA = $this->billWithFindings($sunset, '2026-09-25');
        $billB = $this->billWithFindings($flat, '2026-09-10', ['tariff_category' => 'Business']);
        // Low-only bill: no deadline, not counted.
        $this->makeBill($flat, [['service' => 'water', 'amount_cents' => 100]], ['bill_date' => '2026-10-01', 'total_cents' => 99999]);

        // Submit a dispute on bill B.
        $disputeId = $this->postJson("/api/v1/bills/{$billB->id}/disputes", ['finding_ids' => $billB->findings()->pluck('id')->all()])->json('data.id');
        $this->postJson("/api/v1/disputes/{$disputeId}/submit", ['channel' => 'email', 'submitted_at' => '2026-09-15T08:00:00Z'])->assertOk();

        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertSame(2, $data['properties_count']);
        $this->assertSame(1, $data['open_findings_count']);
        $this->assertSame(27000, $data['potential_overcharge_cents']);
        $this->assertSame(1, $data['active_disputes_count']);
        $this->assertSame(0, $data['recovered_cents']);
        $this->assertSame([
            [
                'type' => 'lodge_dispute',
                'due_on' => '2026-10-25',
                'bill_id' => $billA->id,
                'dispute_id' => null,
                'property_nickname' => 'Sunset Court',
                'label' => 'Lodge a dispute for the 25 Sep 2026 bill',
            ],
            [
                'type' => 'escalate',
                'due_on' => '2026-10-15',
                'bill_id' => $billB->id,
                'dispute_id' => $disputeId,
                'property_nickname' => 'Flat 4',
                'label' => "Escalate if the municipality hasn't responded",
            ],
        ], collect($data['upcoming_deadlines'])->sortByDesc('type')->values()->all());
        // Sorted by due date.
        $this->assertSame(['2026-10-15', '2026-10-25'], array_column($data['upcoming_deadlines'], 'due_on'));
    }

    public function test_overdue_deadlines_are_included_and_dismissed_findings_drop_out(): void
    {
        $property = Property::factory()->for($this->user)->create(['property_type' => 'residential']);
        $bill = $this->billWithFindings($property, '2026-08-01');

        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.upcoming_deadlines.0.due_on', '2026-08-31');

        $this->patchJson('/api/v1/findings/'.$bill->findings()->first()->id, ['status' => 'dismissed'])->assertOk();

        $this->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.upcoming_deadlines', [])
            ->assertJsonPath('data.open_findings_count', 0);
    }

    public function test_a_draft_dispute_keeps_its_lodge_deadline(): void
    {
        $property = Property::factory()->for($this->user)->create(['property_type' => 'residential']);
        $bill = $this->billWithFindings($property, '2026-09-25');
        $disputeId = $this->postJson("/api/v1/bills/{$bill->id}/disputes", ['finding_ids' => $bill->findings()->pluck('id')->all()])->json('data.id');

        $this->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.open_findings_count', 0)
            ->assertJsonPath('data.active_disputes_count', 0)
            ->assertJsonPath('data.upcoming_deadlines.0.type', 'lodge_dispute')
            ->assertJsonPath('data.upcoming_deadlines.0.dispute_id', $disputeId);
    }

    public function test_at_most_twenty_deadlines(): void
    {
        $property = Property::factory()->for($this->user)->create(['property_type' => 'residential']);
        for ($i = 0; $i < 22; $i++) {
            $this->makeBill($property, [['service' => 'water', 'tariff_category' => 'Business', 'amount_cents' => 100]], [
                'bill_date' => CarbonImmutable::parse('2026-01-01')->addDays($i)->toDateString(),
            ]);
        }

        $deadlines = $this->getJson('/api/v1/dashboard')->json('data.upcoming_deadlines');

        $this->assertCount(20, $deadlines);
        $this->assertSame('2026-01-31', $deadlines[0]['due_on']);
    }
}
