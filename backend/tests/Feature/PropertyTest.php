<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Outage;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PropertyTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'nickname' => 'Sunset Court',
            'metro' => 'johannesburg',
            'account_number' => '5501234567',
            'address' => '12 Example Rd, Melville',
            'property_type' => 'sectional_title',
            ...$overrides,
        ];
    }

    public function test_create_returns_the_full_property(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/v1/properties', $this->payload());

        $response->assertCreated()->assertJson(['data' => [
            'nickname' => 'Sunset Court',
            'metro' => 'johannesburg',
            'account_number' => '5501234567',
            'account_number_masked' => '••••4567',
            'address' => '12 Example Rd, Melville',
            'property_type' => 'sectional_title',
            'bills_count' => 0,
            'open_findings_count' => 0,
        ]]);
        $this->assertSame(
            ['id', 'nickname', 'metro', 'account_number', 'account_number_masked', 'address', 'property_type', 'bills_count', 'open_findings_count', 'created_at'],
            array_keys($response->json('data')),
        );
        // Masked account number is sent as real characters, not \u escapes.
        $this->assertStringContainsString('••••4567', $response->getContent());
    }

    public function test_list_masks_account_numbers_and_single_shows_them(): void
    {
        $user = $this->signIn();
        $property = Property::factory()->for($user)->create(['account_number' => '5501234567']);

        $list = $this->getJson('/api/v1/properties')->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertArrayNotHasKey('account_number', $list->json('data.0'));
        $this->assertSame('••••4567', $list->json('data.0.account_number_masked'));

        $this->getJson("/api/v1/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('data.account_number', '5501234567');
    }

    public function test_counts_bills_and_open_medium_and_high_findings(): void
    {
        $user = $this->signIn();
        $property = Property::factory()->for($user)->create(['property_type' => 'residential']);
        // Estimated reading (medium) + business tariff (high) on the last bill.
        $this->waterHistory($property, [10, 12, 11], ['consumption' => 20, 'reading_type' => 'estimated', 'tariff_category' => 'Business']);

        $this->getJson("/api/v1/properties/{$property->id}")
            ->assertJsonPath('data.bills_count', 4)
            ->assertJsonPath('data.open_findings_count', 2);
    }

    public function test_validation(): void
    {
        $this->signIn();

        $this->postJson('/api/v1/properties', $this->payload(['metro' => 'gotham', 'property_type' => 'castle', 'account_number' => '<script>']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['metro', 'property_type', 'account_number']);

        $this->postJson('/api/v1/properties', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nickname', 'metro', 'account_number', 'address', 'property_type']);
    }

    public function test_update_changes_given_fields_and_reaudits_when_the_type_changes(): void
    {
        $user = $this->signIn();
        $property = Property::factory()->for($user)->create(['property_type' => 'commercial']);
        $bills = $this->waterHistory($property, [10], ['consumption' => 10, 'tariff_category' => 'Business']);
        $this->assertSame(0, $bills[1]->findings()->count());

        $this->patchJson("/api/v1/properties/{$property->id}", ['property_type' => 'residential', 'nickname' => 'Home'])
            ->assertOk()
            ->assertJsonPath('data.nickname', 'Home')
            ->assertJsonPath('data.property_type', 'residential')
            ->assertJsonPath('data.metro', 'johannesburg')
            ->assertJsonPath('data.open_findings_count', 1);

        $this->assertSame('tariff_mismatch', $bills[1]->findings()->first()->rule->value);

        $this->patchJson("/api/v1/properties/{$property->id}", ['metro' => 'nowhere'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metro');
    }

    public function test_delete_removes_bills_files_findings_outages_and_disputes(): void
    {
        Storage::fake('bills');
        $user = $this->signIn();
        $property = Property::factory()->for($user)->create(['property_type' => 'residential']);
        $bills = $this->waterHistory($property, [10, 12, 11], ['consumption' => 20, 'reading_type' => 'estimated']);
        $last = end($bills);
        $this->postJson("/api/v1/bills/{$last->id}/disputes", ['finding_ids' => $last->findings()->pluck('id')->all()])->assertCreated();
        Outage::factory()->for($property)->create();

        $upload = $this->post("/api/v1/properties/{$property->id}/bills", ['file' => UploadedFile::fake()->image('bill.jpg')], ['Accept' => 'application/json'])
            ->assertCreated();
        $path = Bill::find($upload->json('data.id'))->file_path;
        Storage::disk('bills')->assertExists($path);

        $this->deleteJson("/api/v1/properties/{$property->id}")->assertNoContent();

        Storage::disk('bills')->assertMissing($path);
        foreach (['properties', 'bills', 'line_items', 'findings', 'outages', 'disputes', 'dispute_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->getJson("/api/v1/properties/{$property->id}")->assertNotFound();
    }
}
