<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Finding;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Property $property;

    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-09T08:00:00Z'));
        $this->user = $this->signIn(User::factory()->create(['name' => 'Thandi M', 'email' => 'thandi@example.com']));
        $this->property = Property::factory()->for($this->user)->create([
            'nickname' => 'Sunset Court',
            'metro' => 'johannesburg',
            'account_number' => '5501234567',
            'address' => '12 Example Rd, Melville',
            'property_type' => 'residential',
        ]);
        // Last bill: Apr 2026, estimated 20 kl (medium, R270.00) on a business tariff (high).
        $bills = $this->waterHistory($this->property, [10, 12, 11], ['consumption' => 20, 'reading_type' => 'estimated', 'tariff_category' => 'Business']);
        $this->bill = end($bills);
    }

    private function findingIds(): array
    {
        return $this->bill->findings()->orderBy('id')->pluck('id')->all();
    }

    private function createDispute(?array $ids = null): array
    {
        return $this->postJson("/api/v1/bills/{$this->bill->id}/disputes", ['finding_ids' => $ids ?? $this->findingIds()])
            ->assertCreated()
            ->json('data');
    }

    public function test_full_lifecycle(): void
    {
        $ids = $this->findingIds();

        // Create: a draft with a generated letter; findings become disputed.
        $dispute = $this->createDispute();
        $this->assertSame([
            'id', 'bill_id', 'property', 'bill', 'status', 'finding_ids', 'amount_disputed_cents', 'letter_subject', 'letter_body',
            'channel', 'municipality_reference', 'lodge_deadline', 'submitted_at', 'response_due_at', 'escalation_level',
            'next_step', 'outcome_amount_cents', 'resolved_at', 'events', 'created_at',
        ], array_keys($dispute));
        $this->assertSame('draft', $dispute['status']);
        $this->assertSame(['id' => $this->property->id, 'nickname' => 'Sunset Court', 'metro' => 'johannesburg'], $dispute['property']);
        $this->assertSame(['id' => $this->bill->id, 'bill_date' => '2026-05-05'], $dispute['bill']);
        $this->assertSame($ids, $dispute['finding_ids']);
        $this->assertSame(27000, $dispute['amount_disputed_cents']);
        $this->assertSame('Dispute of municipal account 5501234567: bill dated 5 May 2026', $dispute['letter_subject']);
        $this->assertSame('2026-06-04', $dispute['lodge_deadline']);
        $this->assertNull($dispute['submitted_at']);
        $this->assertNull($dispute['response_due_at']);
        $this->assertNull($dispute['next_step']);
        $this->assertSame(0, $dispute['escalation_level']);
        $this->assertSame(['created'], array_column($dispute['events'], 'type'));
        $this->assertSame(['disputed'], Finding::whereIn('id', $ids)->pluck('status')->map->value->unique()->values()->all());
        $id = $dispute['id'];

        // Edit the letter while it's a draft.
        $this->patchJson("/api/v1/disputes/{$id}", ['letter_body' => 'My own words.'])
            ->assertOk()
            ->assertJsonPath('data.letter_body', 'My own words.')
            ->assertJsonPath('data.letter_subject', $dispute['letter_subject'])
            ->assertJsonPath('data.events.1.type', 'letter_edited');

        // Submit.
        $submitted = $this->postJson("/api/v1/disputes/{$id}/submit", [
            'channel' => 'email',
            'municipality_reference' => 'QRY-889123',
            'submitted_at' => '2026-10-09T10:00:00+02:00',
        ])->assertOk()->json('data');
        $this->assertSame('submitted', $submitted['status']);
        $this->assertSame('email', $submitted['channel']);
        $this->assertSame('QRY-889123', $submitted['municipality_reference']);
        $this->assertSame('2026-10-09T08:00:00.000000Z', $submitted['submitted_at']);
        $this->assertSame('2026-11-08T08:00:00.000000Z', $submitted['response_due_at']);
        $this->assertSame('Senior revenue official', $submitted['next_step']['name']);
        $this->assertSame('2026-11-08T08:00:00.000000Z', $submitted['next_step']['due_at']);
        $this->assertSame('2026-10-09T08:00:00.000000Z', $submitted['events'][2]['occurred_at']);

        // Three days later the municipality acknowledges.
        $this->travelTo(CarbonImmutable::parse('2026-10-12T10:00:00Z'));
        $this->postJson("/api/v1/disputes/{$id}/events", ['type' => 'acknowledged', 'note' => 'Ref confirmed', 'occurred_at' => '2026-10-12T09:00:00Z'])
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged')
            ->assertJsonPath('data.events.3.type', 'acknowledged')
            ->assertJsonPath('data.events.3.note', 'Ref confirmed');

        $this->postJson("/api/v1/disputes/{$id}/events", ['type' => 'note', 'note' => 'Phoned the call centre.', 'occurred_at' => '2026-10-10T09:00:00Z'])
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');

        // Escalate after no response.
        $this->travelTo(CarbonImmutable::parse('2026-11-10T07:00:00Z'));
        $escalated = $this->postJson("/api/v1/disputes/{$id}/escalate", ['note' => 'No reply after 30 days'])->assertOk()->json('data');
        $this->assertSame('escalated', $escalated['status']);
        $this->assertSame(1, $escalated['escalation_level']);
        $this->assertSame('2026-12-10T07:00:00.000000Z', $escalated['response_due_at']);
        $this->assertSame('Ward councillor or municipal ombudsman', $escalated['next_step']['name']);

        $this->postJson("/api/v1/disputes/{$id}/events", ['type' => 'response_received', 'note' => 'They will re-read the meter.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'escalated');

        // Resolve with a credit.
        $resolved = $this->postJson("/api/v1/disputes/{$id}/resolve", ['outcome' => 'resolved', 'outcome_amount_cents' => 25000, 'note' => 'Credit passed'])
            ->assertOk()->json('data');
        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame(25000, $resolved['outcome_amount_cents']);
        $this->assertSame('2026-11-10T07:00:00.000000Z', $resolved['resolved_at']);
        $this->assertNull($resolved['next_step']);

        // Events are in occurred_at order (the back-dated note sits before the acknowledgement).
        $this->assertSame(
            ['created', 'letter_edited', 'submitted', 'note', 'acknowledged', 'escalated', 'response_received', 'resolved'],
            array_column($resolved['events'], 'type'),
        );

        $this->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.recovered_cents', 25000)
            ->assertJsonPath('data.active_disputes_count', 0);
    }

    public function test_the_letter_covers_everything_required(): void
    {
        $letter = $this->createDispute()['letter_body'];

        foreach ([
            'City of Johannesburg Metropolitan Municipality',
            'Billing and Revenue Department',
            'Account number: 5501234567',
            'Property address: 12 Example Rd, Melville',
            'Bill date: 5 May 2026',
            'Billing period: 1 April 2026 to 30 April 2026',
            'Water: tariff category',
            'Water: estimated meter reading',
            'Amount disputed: R270.00',
            'Amount disputed: to be confirmed',
            'Total amount disputed: R270.00',
            'correct my account',
            'take an actual reading of my water meter',
            'credit my account with any amount',
            'acknowledge this dispute in writing and give me a reference number',
            'section 102(2) of the Local Government: Municipal Systems Act 32 of 2000',
            'should not implement credit control or debt collection measures',
            'specific amount in dispute',
            'Thandi M',
            'prepared with Municipal Bill Auditor. It is not legal advice.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $letter);
        }

        // Plain text, not HTML-escaped.
        $this->assertStringNotContainsString('&quot;', $letter);
        $this->assertStringContainsString('"Business"', $letter);
    }

    public function test_overlapping_findings_are_not_counted_twice(): void
    {
        // An outage over the same April water charge as the estimated reading.
        $this->postJson("/api/v1/properties/{$this->property->id}/outages", [
            'service' => 'water', 'starts_at' => '2026-04-01T00:00:00+02:00', 'ends_at' => '2026-04-16T00:00:00+02:00',
        ])->assertCreated();
        $findings = $this->bill->findings()->get()->keyBy(fn ($f) => $f->rule->value);
        // Estimated: (20 - 11) × 3000; outage: (20 - 5.5) × 3000.
        $this->assertSame(27000, $findings['estimated_reading']->estimated_overcharge_cents);
        $this->assertSame(43500, $findings['outage_charge']->estimated_overcharge_cents);

        $this->getJson("/api/v1/bills/{$this->bill->id}")->assertJsonPath('data.findings_summary.potential_overcharge_cents', 43500);

        $dispute = $this->createDispute([$findings['estimated_reading']->id, $findings['outage_charge']->id]);
        $this->assertSame(43500, $dispute['amount_disputed_cents']);
        $this->assertStringContainsString('Total amount disputed: R435.00', $dispute['letter_body']);
        $this->assertStringContainsString('counted only once', $dispute['letter_body']);
    }

    public function test_only_open_findings_on_this_bill_can_be_disputed(): void
    {
        $url = "/api/v1/bills/{$this->bill->id}/disputes";
        [$first, $second] = $this->findingIds();

        $this->postJson($url, ['finding_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('finding_ids');
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('finding_ids');

        $otherBill = $this->makeBill($this->property, [['service' => 'water', 'tariff_category' => 'Business', 'amount_cents' => 100]], ['bill_date' => '2026-06-01']);
        $this->postJson($url, ['finding_ids' => [$otherBill->findings()->first()->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('finding_ids');

        $this->patchJson("/api/v1/findings/{$first}", ['status' => 'dismissed'])->assertOk();
        $this->postJson($url, ['finding_ids' => [$first, $second]])
            ->assertUnprocessable()->assertJsonValidationErrors('finding_ids');

        $this->createDispute([$second]);
        $this->postJson($url, ['finding_ids' => [$second]])
            ->assertUnprocessable()->assertJsonValidationErrors('finding_ids');

        $this->assertDatabaseCount('disputes', 1);
    }

    public function test_deleting_a_draft_reopens_its_findings(): void
    {
        $id = $this->createDispute()['id'];

        $this->deleteJson("/api/v1/disputes/{$id}")->assertNoContent();

        $this->assertDatabaseCount('disputes', 0);
        $this->assertDatabaseCount('dispute_events', 0);
        $this->assertSame(['open'], $this->bill->findings()->pluck('status')->map->value->unique()->values()->all());
    }

    public function test_invalid_transitions_return_422(): void
    {
        $id = $this->createDispute()['id'];

        // Draft: can't escalate, resolve or record municipal events.
        $this->postJson("/api/v1/disputes/{$id}/escalate")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/disputes/{$id}/resolve", ['outcome' => 'resolved'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/disputes/{$id}/events", ['type' => 'acknowledged'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/disputes/{$id}/events", ['type' => 'note', 'note' => 'Drafting'])->assertOk();

        $this->postJson("/api/v1/disputes/{$id}/submit", ['channel' => 'walk_in'])->assertOk();

        // Submitted: no more edits, deletes or second submits.
        $this->patchJson("/api/v1/disputes/{$id}", ['letter_body' => 'Changed'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->deleteJson("/api/v1/disputes/{$id}")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/disputes/{$id}/submit", ['channel' => 'email'])->assertUnprocessable()->assertJsonValidationErrors('status');

        // Escalate through every step, then no further step exists.
        foreach ([1, 2, 3] as $level) {
            $this->postJson("/api/v1/disputes/{$id}/escalate")->assertOk()->assertJsonPath('data.escalation_level', $level);
        }
        $this->getJson("/api/v1/disputes/{$id}")->assertJsonPath('data.next_step', null);
        $this->postJson("/api/v1/disputes/{$id}/escalate")->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->postJson("/api/v1/disputes/{$id}/resolve", ['outcome' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.outcome_amount_cents', null);

        // Final: nothing but notes.
        $this->postJson("/api/v1/disputes/{$id}/resolve", ['outcome' => 'resolved'])->assertUnprocessable();
        $this->postJson("/api/v1/disputes/{$id}/escalate")->assertUnprocessable();
        $this->postJson("/api/v1/disputes/{$id}/events", ['type' => 'response_received'])->assertUnprocessable();
        $this->postJson("/api/v1/disputes/{$id}/events", ['type' => 'note', 'note' => 'Taking it further.'])->assertOk();
    }

    public function test_request_validation(): void
    {
        $id = $this->createDispute()['id'];

        $this->postJson("/api/v1/disputes/{$id}/submit", ['channel' => 'pigeon'])->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->postJson("/api/v1/disputes/{$id}/events", ['type' => 'escalated'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson("/api/v1/disputes/{$id}/events", ['type' => 'note'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/disputes/{$id}/resolve", ['outcome' => 'won'])->assertUnprocessable()->assertJsonValidationErrors('outcome');
        $this->patchJson("/api/v1/disputes/{$id}", ['letter_subject' => ''])->assertUnprocessable()->assertJsonValidationErrors('letter_subject');
    }

    public function test_submitted_at_cannot_be_in_the_future(): void
    {
        $id = $this->createDispute()['id'];

        // A mistyped year would push the response deadline months out.
        $this->postJson("/api/v1/disputes/{$id}/submit", ['channel' => 'email', 'submitted_at' => '2027-06-01T00:00:00Z'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.submitted_at', ["The date you sent the dispute can't be in the future."]);
        $this->getJson("/api/v1/disputes/{$id}")->assertJsonPath('data.status', 'draft');

        $this->postJson("/api/v1/disputes/{$id}/submit", ['channel' => 'email', 'submitted_at' => '2026-10-09T08:06:00Z'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('submitted_at');

        // A phone clock a few minutes fast is fine.
        $this->postJson("/api/v1/disputes/{$id}/submit", ['channel' => 'email', 'submitted_at' => '2026-10-09T10:04:00+02:00'])
            ->assertOk()
            ->assertJsonPath('data.submitted_at', '2026-10-09T08:04:00.000000Z');
    }

    public function test_event_dates_cannot_be_in_the_future(): void
    {
        $id = $this->createDispute()['id'];
        $this->postJson("/api/v1/disputes/{$id}/submit", ['channel' => 'email'])->assertOk();

        foreach (['acknowledged', 'response_received', 'note'] as $type) {
            $this->postJson("/api/v1/disputes/{$id}/events", ['type' => $type, 'note' => 'Called them', 'occurred_at' => '2026-10-10T09:00:00Z'])
                ->assertUnprocessable()
                ->assertJsonPath('errors.occurred_at', ["This date can't be in the future."]);
        }

        $dispute = $this->getJson("/api/v1/disputes/{$id}")->json('data');
        $this->assertSame('submitted', $dispute['status']);
        $this->assertSame(['created', 'submitted'], array_column($dispute['events'], 'type'));

        $this->postJson("/api/v1/disputes/{$id}/events", ['type' => 'acknowledged', 'occurred_at' => '2026-10-09T08:00:00Z'])
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');
    }

    public function test_submitted_at_defaults_to_now(): void
    {
        $id = $this->createDispute()['id'];

        $this->postJson("/api/v1/disputes/{$id}/submit", ['channel' => 'portal'])
            ->assertOk()
            ->assertJsonPath('data.submitted_at', '2026-10-09T08:00:00.000000Z')
            ->assertJsonPath('data.response_due_at', '2026-11-08T08:00:00.000000Z');
    }

    public function test_list_uses_the_collection_shape_newest_first(): void
    {
        [$first, $second] = $this->findingIds();
        $older = $this->createDispute([$first])['id'];
        $this->travel(1)->minutes();
        $newer = $this->createDispute([$second])['id'];

        $response = $this->getJson('/api/v1/disputes')->assertOk();

        $this->assertSame([$newer, $older], array_column($response->json('data'), 'id'));
        $this->assertArrayNotHasKey('letter_body', $response->json('data.0'));
        $this->assertArrayNotHasKey('events', $response->json('data.0'));
        $this->assertArrayHasKey('letter_subject', $response->json('data.0'));
        $this->assertArrayHasKey('next_step', $response->json('data.0'));
    }

    public function test_the_list_masks_account_numbers_in_the_subject(): void
    {
        [$first, $second] = $this->findingIds();
        $generated = $this->createDispute([$first])['id'];
        $edited = $this->createDispute([$second])['id'];
        // A subject you wrote yourself, with an older account number in it.
        $this->patchJson("/api/v1/disputes/{$edited}", ['letter_subject' => 'Query on account 7700112233 (now 5501234567)'])->assertOk();

        $response = $this->getJson('/api/v1/disputes')->assertOk();

        $subjects = collect($response->json('data'))->pluck('letter_subject', 'id');
        $this->assertSame('Dispute of municipal account ••••4567: bill dated 5 May 2026', $subjects[$generated]);
        $this->assertSame('Query on account ••••2233 (now ••••4567)', $subjects[$edited]);
        $this->assertStringNotContainsString('5501234567', $response->getContent());
        $this->assertStringNotContainsString('7700112233', $response->getContent());

        // The single dispute still has the full subject, ready to send.
        $this->getJson("/api/v1/disputes/{$generated}")
            ->assertJsonPath('data.letter_subject', 'Dispute of municipal account 5501234567: bill dated 5 May 2026');
    }
}
