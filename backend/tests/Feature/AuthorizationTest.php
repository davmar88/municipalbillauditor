<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Dispute;
use App\Models\Finding;
use App\Models\Outage;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Another user's property, bill, finding, outage and dispute are all 404.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private Bill $bill;

    private Finding $finding;

    private Outage $outage;

    private Dispute $dispute;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('bills');

        $owner = $this->signIn();
        $this->property = Property::factory()->for($owner)->create(['property_type' => 'residential']);
        $bills = $this->waterHistory($this->property, [10, 12, 11], ['consumption' => 20, 'reading_type' => 'estimated', 'tariff_category' => 'Business']);
        $this->bill = end($bills);
        [$disputed, $this->finding] = $this->bill->findings()->orderBy('id')->get()->all();
        $this->dispute = Dispute::findOrFail(
            $this->postJson("/api/v1/bills/{$this->bill->id}/disputes", ['finding_ids' => [$disputed->id]])->json('data.id'),
        );
        $this->outage = Outage::factory()->for($this->property)->create();

        // Everything below runs as someone else.
        $this->app['auth']->forgetGuards();
        $this->signIn(User::factory()->create());
    }

    public function test_another_users_property_is_not_found(): void
    {
        $id = $this->property->id;

        $this->getJson("/api/v1/properties/{$id}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->patchJson("/api/v1/properties/{$id}", ['nickname' => 'Mine now'])->assertNotFound();
        $this->deleteJson("/api/v1/properties/{$id}")->assertNotFound();
        $this->getJson("/api/v1/properties/{$id}/bills")->assertNotFound();
        $this->postJson("/api/v1/properties/{$id}/bills", ['line_items' => [['service' => 'water', 'amount_cents' => 1]]])->assertNotFound();
        $this->post("/api/v1/properties/{$id}/bills", ['file' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson("/api/v1/properties/{$id}/outages")->assertNotFound();
        $this->postJson("/api/v1/properties/{$id}/outages", ['service' => 'water', 'starts_at' => '2026-04-01', 'ends_at' => '2026-04-02'])->assertNotFound();

        $this->getJson('/api/v1/properties')->assertOk()->assertExactJson(['data' => []]);
        $this->assertSame($this->property->nickname, $this->property->fresh()->nickname);
    }

    public function test_another_users_bill_is_not_found(): void
    {
        $id = $this->bill->id;

        $this->getJson("/api/v1/bills/{$id}")->assertNotFound();
        $this->putJson("/api/v1/bills/{$id}", ['line_items' => [['service' => 'water', 'amount_cents' => 1]]])->assertNotFound();
        $this->postJson("/api/v1/bills/{$id}/audit")->assertNotFound();
        $this->getJson("/api/v1/bills/{$id}/file")->assertNotFound();
        $this->postJson("/api/v1/bills/{$id}/disputes", ['finding_ids' => [$this->finding->id]])->assertNotFound();
        $this->deleteJson("/api/v1/bills/{$id}")->assertNotFound();

        $this->assertDatabaseHas('bills', ['id' => $id]);
        $this->assertSame(1, $this->bill->lineItems()->count());
    }

    public function test_another_users_finding_is_not_found(): void
    {
        $this->patchJson("/api/v1/findings/{$this->finding->id}", ['status' => 'dismissed'])->assertNotFound();

        $this->assertSame('open', $this->finding->fresh()->status->value);
    }

    public function test_another_users_outage_is_not_found(): void
    {
        $this->deleteJson("/api/v1/outages/{$this->outage->id}")->assertNotFound();

        $this->assertDatabaseHas('outages', ['id' => $this->outage->id]);
    }

    public function test_another_users_dispute_is_not_found(): void
    {
        $id = $this->dispute->id;

        $this->getJson("/api/v1/disputes/{$id}")->assertNotFound();
        $this->patchJson("/api/v1/disputes/{$id}", ['letter_body' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/disputes/{$id}/submit", ['channel' => 'email'])->assertNotFound();
        $this->postJson("/api/v1/disputes/{$id}/events", ['type' => 'note', 'note' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/disputes/{$id}/escalate")->assertNotFound();
        $this->postJson("/api/v1/disputes/{$id}/resolve", ['outcome' => 'resolved'])->assertNotFound();
        $this->deleteJson("/api/v1/disputes/{$id}")->assertNotFound();

        $this->getJson('/api/v1/disputes')->assertOk()->assertExactJson(['data' => []]);
        $this->assertSame('draft', $this->dispute->fresh()->status->value);
        $this->assertSame(1, $this->dispute->events()->count());
    }

    public function test_dashboard_and_export_only_show_your_own_data(): void
    {
        $this->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.properties_count', 0)
            ->assertJsonPath('data.open_findings_count', 0)
            ->assertJsonPath('data.upcoming_deadlines', []);

        $export = $this->getJson('/api/v1/me/export')->json();
        $this->assertSame([], $export['properties']);
        $this->assertSame([], $export['disputes']);
    }

    public function test_unknown_and_malformed_ids_are_not_found(): void
    {
        $this->getJson('/api/v1/bills/999999')->assertNotFound();
        $this->getJson('/api/v1/bills/abc')->assertNotFound();
        $this->getJson('/api/v1/properties/1;DROP')->assertNotFound();
    }
}
