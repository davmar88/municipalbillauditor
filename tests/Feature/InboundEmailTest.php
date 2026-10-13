<?php

namespace Tests\Feature;

use App\Enums\BillSource;
use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /**
     * A Mailgun route forward, signed with the webhook signing key.
     *
     * @param  array<string, mixed>  $fields
     */
    private function mailgun(array $fields, ?int $timestamp = null, ?string $token = null, ?string $key = 'mailgun-signing-key')
    {
        $timestamp ??= now()->getTimestamp();
        $token ??= Str::random(50);

        return $this->post(route('inbound.email'), [
            ...$fields,
            'timestamp' => (string) $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp.$token, (string) $key),
        ]);
    }

    public function test_mailgun_forwards_are_verified_by_their_signature(): void
    {
        config(['services.mailgun.webhook_signing_key' => 'mailgun-signing-key']);
        $organisation = Organisation::factory()->create();
        $fields = fn () => [
            'recipient' => "{$organisation->forwarding_code}@bills.example.com",
            'sender' => 'thandi@example.com',
            'subject' => 'Fwd: Your statement',
            'body-plain' => 'See attached.',
            'attachment-1' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
        ];

        $this->mailgun($fields())->assertStatus(202)->assertJson(['accepted' => 1]);
        $this->assertSame(BillSource::Email, Bill::sole()->source);

        // Wrong key, stale, from the future, or replayed: refused.
        $this->mailgun($fields(), key: 'guessed')->assertNotFound();
        $this->mailgun($fields(), timestamp: now()->subMinutes(10)->getTimestamp())->assertNotFound();
        $this->mailgun($fields(), timestamp: now()->addMinutes(10)->getTimestamp())->assertNotFound();
        $this->mailgun($fields(), token: 'same-token')->assertStatus(202);
        $this->mailgun($fields(), token: 'same-token')->assertNotFound();

        $this->assertSame(2, Bill::count());
    }

    public function test_without_a_signing_key_unsigned_posts_are_refused(): void
    {
        config(['services.mailgun.webhook_signing_key' => null]);

        $this->mailgun(['recipient' => 'x@bills.example.com'])->assertNotFound();
    }

    public function test_a_wrong_secret_is_turned_away(): void
    {
        $organisation = Organisation::factory()->create();

        $this->forward("{$organisation->forwarding_code}@bills.example.com", ['attachment1' => UploadedFile::fake()->create('bill.pdf', 10, 'application/pdf')], 'guess')
            ->assertNotFound();

        $this->assertSame(0, Bill::count());
    }
}
