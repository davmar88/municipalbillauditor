<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutageTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    /** @var list<Bill> */
    private array $bills;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->signIn();
        $this->property = Property::factory()->for($user)->create();
        // January–April 2026, 12 kl each; April is the bill under test.
        $this->bills = $this->waterHistory($this->property, [12, 12, 12], ['consumption' => 12]);
    }

    public function test_recording_an_outage_reaudits_overlapping_bills(): void
    {
        $april = $this->bills[3];
        $this->assertSame(0, $april->findings()->count());

        $response = $this->postJson("/api/v1/properties/{$this->property->id}/outages", [
            'service' => 'water',
            // 15 of April's 30 days, sent with a South African offset.
            'starts_at' => '2026-04-01T00:00:00+02:00',
            'ends_at' => '2026-04-16T00:00:00+02:00',
            'notes' => 'Rand Water maintenance',
        ]);

        $response->assertCreated()->assertExactJson(['data' => [
            'id' => $response->json('data.id'),
            'property_id' => $this->property->id,
            'service' => 'water',
            'starts_at' => '2026-03-31T22:00:00.000000Z',
            'ends_at' => '2026-04-15T22:00:00.000000Z',
            'notes' => 'Rand Water maintenance',
            'created_at' => $response->json('data.created_at'),
        ]]);

        $finding = $april->findings()->first();
        $this->assertSame('outage_charge', $finding->rule->value);
        $this->assertSame('medium', $finding->severity->value);
        $this->assertSame(18000, $finding->estimated_overcharge_cents);
        // March doesn't overlap.
        $this->assertSame(0, $this->bills[2]->findings()->count());

        $this->getJson("/api/v1/properties/{$this->property->id}/outages")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $response->json('data.id'));

        $this->deleteJson('/api/v1/outages/'.$response->json('data.id'))->assertNoContent();

        $this->assertSame(0, $april->findings()->count());
        $this->assertDatabaseCount('outages', 0);
    }

    public function test_validation(): void
    {
        $url = "/api/v1/properties/{$this->property->id}/outages";

        $this->postJson($url, ['service' => 'sewerage', 'starts_at' => '2026-04-01T00:00:00Z', 'ends_at' => '2026-04-02T00:00:00Z'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service');

        $this->postJson($url, ['service' => 'water', 'starts_at' => '2026-04-02T00:00:00Z', 'ends_at' => '2026-04-01T00:00:00Z'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->postJson($url, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service', 'starts_at', 'ends_at']);
    }
}
