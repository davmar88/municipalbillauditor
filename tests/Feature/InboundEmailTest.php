<?php

namespace Tests\Feature;

use App\Enums\BillSource;
use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Forwarded e-bills arrive from the email provider's inbound webhook.
 */
class InboundEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['auditor.inbound_email_domain' => 'bills.example.com']);
    }

    private function forward(string $to, array $files, string $secret = 'test-inbound-secret')
    {
        return $this->post(route('inbound.email'), ['to' => $to, ...$files], ['X-Inbound-Secret' => $secret]);
    }

    public function test_a_forwarded_bill_is_stored_and_read(): void
    {
        $organisation = Organisation::factory()->agent()->create();

        $response = $this->forward("Bills <{$organisation->forwarding_code}@bills.example.com>", [
            'attachment1' => UploadedFile::fake()->create('statement.pdf', 200, 'application/pdf'),
            'attachment2' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
        ]);

        $response->assertStatus(202)->assertJson(['accepted' => 1]);
        $bill = Bill::sole();
        $this->assertSame($organisation->id, $bill->organisation_id);
        $this->assertSame(BillSource::Email, $bill->source);
        Storage::disk('local')->assertExists($bill->file_path);
        // No API key in tests, so it waits for its details.
        $this->assertSame(BillStatus::NeedsEntry, $bill->status);
    }

    public function test_an_unknown_address_gets_the_same_answer(): void
    {
        $this->forward('nobody@bills.example.com', ['attachment1' => UploadedFile::fake()->create('bill.pdf', 10, 'application/pdf')])
            ->assertStatus(202)
            ->assertJson(['accepted' => 0]);

        $this->assertSame(0, Bill::count());
    }

    public function test_a_wrong_secret_is_turned_away(): void
    {
        $organisation = Organisation::factory()->create();

        $this->forward("{$organisation->forwarding_code}@bills.example.com", ['attachment1' => UploadedFile::fake()->create('bill.pdf', 10, 'application/pdf')], 'guess')
            ->assertNotFound();

        $this->assertSame(0, Bill::count());
    }
}
