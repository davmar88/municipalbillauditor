<?php

namespace Tests\Feature;

use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POPIA: account numbers and addresses are encrypted at rest.
 */
class EncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_account_number_and_address_are_not_stored_in_plaintext(): void
    {
        $this->signIn();

        $id = $this->postJson('/api/v1/properties', [
            'nickname' => 'Sunset Court',
            'metro' => 'johannesburg',
            'account_number' => '5501234567',
            'address' => '12 Example Rd, Melville',
            'property_type' => 'residential',
        ])->assertCreated()->json('data.id');

        $row = (array) DB::table('properties')->where('id', $id)->first();
        $raw = json_encode($row);
        $this->assertStringNotContainsString('5501234567', $raw);
        $this->assertStringNotContainsString('4567', $row['account_number']);
        $this->assertStringNotContainsString('Example Rd', $raw);

        // The model decrypts transparently.
        $this->assertSame('5501234567', Property::find($id)->account_number);
        $this->assertSame('12 Example Rd, Melville', Property::find($id)->address);
    }

    public function test_dispute_letters_are_encrypted_too(): void
    {
        $user = $this->signIn();
        $property = Property::factory()->for($user)->create(['account_number' => '5501234567', 'property_type' => 'residential']);
        $bills = $this->waterHistory($property, [10], ['consumption' => 10, 'tariff_category' => 'Business']);
        $bill = end($bills);

        $id = $this->postJson("/api/v1/bills/{$bill->id}/disputes", ['finding_ids' => $bill->findings()->pluck('id')->all()])
            ->assertCreated()
            ->assertJsonPath('data.letter_subject', fn ($subject) => str_contains($subject, '5501234567'))
            ->json('data.id');

        $raw = json_encode(DB::table('disputes')->where('id', $id)->first());
        $this->assertStringNotContainsString('5501234567', $raw);

        // Nothing in the database holds the account number in plaintext.
        foreach (['users', 'properties', 'bills', 'line_items', 'findings', 'disputes', 'dispute_events'] as $table) {
            $this->assertStringNotContainsString('5501234567', json_encode(DB::table($table)->get()), $table);
        }
    }
}
