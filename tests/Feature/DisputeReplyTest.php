<?php

namespace Tests\Feature;

use App\Enums\DisputeEventKind;
use App\Enums\OrganisationKind;
use App\Enums\ReplyClassification;
use App\Models\ClaudeUsageRecord;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\Municipality;
use App\Models\User;
use App\Notifications\CustomerNotice;
use App\Services\BillIntake;
use App\Services\Claude\ClaudeUsage;
use App\Services\Claude\TokenCount;
use App\Services\Disputes\DisputeApproval;
use App\Services\DisputeService;
use App\Services\Replies\ReplyReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeReplyReader;
use Tests\TestCase;

/**
 * Municipal replies come back to the dispute's own address, are read by
 * Claude, and move the dispute on.
 */
class DisputeReplyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Dispute $dispute;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        config(['auditor.inbound_email_domain' => 'mail.example.org']);

        $this->user = User::factory()->forKind(OrganisationKind::Household)->withCredit()->create();
        Municipality::firstWhere('slug', 'johannesburg')->update(['billing_email' => 'billing@joburg.example.org', 'billing_email_verified_at' => now()]);
        $bill = app(BillIntake::class)->createSample($this->user->organisation, $this->user);
        $this->dispute = app(DisputeApproval::class)->approve(app(DisputeService::class)->draft($bill, $this->user), $this->user, null, true);
    }

    private function reader(array $output, ?ClaudeUsage $usage = null): FakeReplyReader
    {
        $reader = new FakeReplyReader($output, $usage);
        $this->app->instance(ReplyReader::class, $reader);

        return $reader;
    }

    private function reply(string $body, ?string $to = null, string $from = 'Revenue <revenue@joburg.example.org>', ?string $messageId = null, array $files = [])
    {
        return $this->post(route('inbound.email'), [
            'to' => $to ?? "Disputes <{$this->dispute->replyAddress()}>",
            'from' => $from,
            'subject' => 'RE: '.$this->dispute->letter_subject,
            'body-plain' => $body,
            'Message-Id' => $messageId ?? '<'.Str::random(12).'@joburg.example.org>',
            ...$files,
        ], ['X-Inbound-Secret' => 'test-inbound-secret']);
    }

    private function noticeOfKind(string $kind): CustomerNotice
    {
        $sent = Notification::sent($this->user, CustomerNotice::class)->filter(fn (CustomerNotice $n) => $n->kind === $kind);
        $this->assertCount(1, $sent, "Expected one {$kind} email.");

        return $sent->first();
    }

    public function test_an_acknowledgement_records_the_reference_and_costs_are_recorded(): void
    {
        $reader = $this->reader(
            FakeReplyReader::output('acknowledgement', ['reference' => 'JHB-77120', 'summary' => 'Johannesburg logged your query.']),
            new ClaudeUsage([new TokenCount('claude-opus-5-5', 900, 120)]),
        );

        $this->reply('Your query has been logged under reference JHB-77120.')->assertStatus(202)->assertJson(['accepted' => 1]);

        $message = DisputeMessage::query()->where('direction', DisputeMessage::IN)->sole();
        $this->assertSame(ReplyClassification::Acknowledgement, $message->classification);
        $this->assertSame('revenue@joburg.example.org', str($message->from_address)->between('<', '>')->value());
        $this->assertStringContainsString('JHB-77120', $reader->read[0]['body']);
        $this->assertStringContainsString('City of Johannesburg', $reader->read[0]['context']);

        $this->dispute->refresh();
        $this->assertSame('JHB-77120', $this->dispute->reference);
        $this->assertTrue($this->dispute->acknowledged_on->isToday());
        $this->assertSame('reply_read', ClaudeUsageRecord::sole()->purpose);
        $this->assertTrue(ClaudeUsageRecord::sole()->subject->is($this->dispute));

        $notice = $this->noticeOfKind('municipality_replied');
        $this->assertStringContainsString('JHB-77120', implode(' ', $notice->lines));
    }

    public function test_a_request_for_information_tells_the_customer_what_to_do(): void
    {
        $this->reader(FakeReplyReader::output('more_information', [
            'information_requested' => 'a photo of the water meter',
            'customer_action' => 'Send a dated photo of your water meter.',
        ]));

        $this->reply('Please send a photo of the meter.');

        $this->assertTrue($this->dispute->events()->where('kind', DisputeEventKind::InfoRequested)->exists());
        $notice = $this->noticeOfKind('municipality_replied');
        $this->assertSame('Send a dated photo of your water meter.', $notice->lines[array_key_last($notice->lines)]);
    }

    public function test_an_upheld_dispute_waits_for_the_credit(): void
    {
        $this->reader(FakeReplyReader::output('upheld', ['credit_amount' => 924, 'summary' => 'They will credit the account.']));

        $this->reply('We will credit R924.00 to your account.');

        $this->dispute->refresh();
        $this->assertSame('awaiting_credit', $this->dispute->stage()->value);
        $this->assertStringContainsString('R924.00', $this->dispute->events()->where('kind', DisputeEventKind::Upheld)->sole()->note);
        $this->assertStringContainsString('R924.00', implode(' ', $this->noticeOfKind('municipality_replied')->lines));
    }

    public function test_a_rejection_gives_the_reason(): void
    {
        $this->reader(FakeReplyReader::output('rejected', ['reason' => 'The reading was actual.']));

        $this->reply('Your dispute is declined.');

        $this->assertSame('The reading was actual.', $this->dispute->events()->where('kind', DisputeEventKind::Rejected)->sole()->note);
        $this->assertStringContainsString('The reading was actual.', implode(' ', $this->noticeOfKind('municipality_replied')->lines));
    }

    public function test_an_automatic_reply_is_kept_but_changes_nothing(): void
    {
        $this->reader(FakeReplyReader::output('auto_reply'));
        $events = $this->dispute->events()->count();

        $this->reply('I am out of the office until Monday.');

        $this->assertSame(ReplyClassification::AutoReply, DisputeMessage::where('direction', 'in')->sole()->classification);
        $this->assertSame($events, $this->dispute->events()->count());
        Notification::assertNotSentTo($this->user, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'municipality_replied');
    }

    public function test_a_reply_claude_cannot_read_goes_to_our_team(): void
    {
        // No API key in tests: replies wait for our team.
        $this->reply('Ons het u navraag ontvang. Verwysing 5521.');
        $message = DisputeMessage::where('direction', 'in')->sole();
        $this->assertSame(ReplyClassification::Unread, $message->classification);

        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->get(route('staff.index'))->assertInertia(fn ($page) => $page
            ->has('replies', 1)
            ->where('replies.0.id', $message->id),
        );

        $this->post(route('staff.replies.record', $message), ['outcome' => 'acknowledgement', 'reference' => '5521'])->assertSessionHasNoErrors();

        $this->assertSame('5521', $this->dispute->refresh()->reference);
        $this->assertSame(ReplyClassification::Acknowledgement, $message->refresh()->classification);
        $this->get(route('staff.index'))->assertInertia(fn ($page) => $page->has('replies', 0));
    }

    public function test_the_customers_own_reply_all_changes_nothing_and_emails_nobody(): void
    {
        $reader = $this->reader(FakeReplyReader::output('upheld'));
        $events = $this->dispute->events()->count();

        $this->reply('So you will reverse the R924?', from: "{$this->user->name} <".strtoupper($this->user->email).'>');

        $message = DisputeMessage::where('direction', 'in')->sole();
        $this->assertSame('customer', $message->kind);
        $this->assertNull($message->classification);
        $this->assertSame([], $reader->read);
        $this->assertSame($events, $this->dispute->events()->count());
        Notification::assertNotSentTo($this->user, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'municipality_replied');
    }

    public function test_a_reply_from_another_domain_waits_for_our_team(): void
    {
        $this->reader(FakeReplyReader::output('upheld', ['summary' => 'Credit approved.']));

        $this->reply('Your dispute is upheld.', from: 'Someone <someone@gmail.example.com>');

        $message = DisputeMessage::where('direction', 'in')->sole();
        $this->assertSame(ReplyClassification::Unread, $message->classification);
        $this->assertSame('upheld', $message->reading['suggested']);
        $this->assertNull($this->dispute->refresh()->reply_received_on);
        Notification::assertNotSentTo($this->user, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'municipality_replied');

        $this->actingAs(User::factory()->staff()->create())->get(route('staff.index'))->assertInertia(fn ($page) => $page
            ->where('replies.0.unknownSender', true)
            ->where('replies.0.suggestion', 'Upheld: Credit approved.'),
        );
    }

    public function test_an_outcome_given_only_in_an_attached_letter_is_read(): void
    {
        Storage::fake('local');
        $reader = $this->reader(FakeReplyReader::output('upheld', ['credit_amount' => 924]));

        $this->reply('Please find attached.', files: [
            'attachment-1' => UploadedFile::fake()->createWithContent('outcome.pdf', '%PDF-1.7 Your dispute is upheld; R924.00 will be credited.'),
            'attachment-2' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
        ]);

        $this->assertCount(1, $reader->read[0]['files']);
        $this->assertSame('application/pdf', $reader->read[0]['files'][0]['mime']);
        $this->assertStringContainsString('R924.00 will be credited', $reader->read[0]['files'][0]['contents']);
        $this->assertSame('awaiting_credit', $this->dispute->refresh()->stage()->value);

        $message = DisputeMessage::where('direction', 'in')->sole();
        Storage::disk('local')->assertExists($message->attachments[0]['path']);
        $this->actingAs($this->user)->get(route('disputes.messages.file', [$this->dispute, $message, 0]))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('disputes.messages.file', [$this->dispute, $message, 0]))->assertForbidden();
    }

    public function test_the_same_message_posted_twice_is_stored_once(): void
    {
        $this->reader(FakeReplyReader::output('acknowledgement', ['reference' => 'JHB-9']));

        $this->reply('Logged as JHB-9.', messageId: '<same@joburg.example.org>')->assertStatus(202);
        $this->reply('Logged as JHB-9.', messageId: '<same@joburg.example.org>')->assertStatus(202);

        $this->assertSame(1, DisputeMessage::where('direction', 'in')->count());
        $this->assertSame(1, $this->dispute->events()->where('kind', DisputeEventKind::Acknowledged)->count());
        $this->noticeOfKind('municipality_replied');
    }

    public function test_an_unknown_dispute_address_is_treated_like_any_other(): void
    {
        $this->reader(FakeReplyReader::output('acknowledgement'));

        $this->reply('Hello', 'd-aaaaaaaaaaaaaaaaaaaaaaaa@mail.example.org')->assertStatus(202)->assertJson(['accepted' => 0]);
        $this->reply('Hello')->assertStatus(202);
        $this->post(route('inbound.email'), ['to' => $this->dispute->replyAddress()], ['X-Inbound-Secret' => 'wrong'])->assertNotFound();

        $this->assertSame(1, DisputeMessage::where('direction', 'in')->count());
    }
}
