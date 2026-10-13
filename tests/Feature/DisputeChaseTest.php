<?php

namespace Tests\Feature;

use App\Enums\DisputeEventKind;
use App\Enums\OrganisationKind;
use App\Mail\DisputeEmail;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\Municipality;
use App\Models\User;
use App\Notifications\CustomerNotice;
use App\Services\BillIntake;
use App\Services\Disputes\DisputeApproval;
use App\Services\DisputeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The daily run chases emailed disputes: a follow-up, then the municipal
 * manager, then a Public Protector complaint for the customer to lodge.
 * Johannesburg's placeholder rules: 21 days to reply, 30 for the councillor.
 */
class DisputeChaseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Dispute $dispute;

    private Municipality $johannesburg;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        config(['auditor.inbound_email_domain' => 'mail.example.org']);

        $this->user = User::factory()->forKind(OrganisationKind::Household)->withCredit()->create();
        $this->johannesburg = Municipality::firstWhere('slug', 'johannesburg');
        $this->johannesburg->update(['billing_email' => 'billing@joburg.example.org', 'billing_email_verified_at' => now()]);
        $bill = app(BillIntake::class)->createSample($this->user->organisation, $this->user);
        $this->dispute = app(DisputeApproval::class)->approve(app(DisputeService::class)->draft($bill, $this->user), $this->user, null, true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function on(int $daysAfterLodging): void
    {
        CarbonImmutable::setTestNow($this->dispute->lodged_on->addDays($daysAfterLodging)->setTime(7, 0));
        $this->artisan('disputes:remind')->assertSuccessful();
        $this->dispute->refresh();
    }

    /**
     * @return list<DisputeEmail>
     */
    private function emailsTo(string $address): array
    {
        return Mail::sent(DisputeEmail::class, fn (DisputeEmail $mail) => $mail->hasTo($address))->values()->all();
    }

    public function test_no_acknowledgement_by_the_reply_date_gets_one_follow_up_on_the_same_thread(): void
    {
        $original = $this->dispute->messages()->sole();

        $this->on(21);
        $this->assertCount(1, $this->emailsTo('billing@joburg.example.org'));

        $this->on(22);
        $this->on(23);

        $emails = $this->emailsTo('billing@joburg.example.org');
        $this->assertCount(2, $emails);
        $followUp = $emails[1];
        $this->assertSame($original->message_id, $followUp->inReplyTo);
        $this->assertTrue($followUp->hasSubject('Re: '.$this->dispute->letter_subject));
        $this->assertTrue($followUp->hasReplyTo($this->dispute->replyAddress()));
        $this->assertStringContainsString('have not received one', $followUp->body);
        $this->assertTrue($this->dispute->events()->where('kind', DisputeEventKind::FollowedUp)->exists());
    }

    public function test_an_acknowledged_dispute_is_not_followed_up(): void
    {
        app(DisputeService::class)->record($this->dispute, DisputeEventKind::Acknowledged, CarbonImmutable::today(), ['reference' => 'JHB-1']);

        $this->on(25);

        $this->assertCount(1, $this->emailsTo('billing@joburg.example.org'));
    }

    public function test_an_unanswered_dispute_goes_to_the_manager_then_to_the_customer_for_the_public_protector(): void
    {
        $this->johannesburg->update(['escalation_email' => 'manager@joburg.example.org']);

        // Reply due day 21, councillor step day 22, its window ends day 52.
        $this->on(22);
        $this->on(52);
        $this->assertSame([], $this->emailsTo('manager@joburg.example.org'));

        $this->on(53);
        $escalation = $this->emailsTo('manager@joburg.example.org');
        $this->assertCount(1, $escalation);
        $this->assertTrue($escalation[0]->hasCc($this->user->email));
        $this->assertStringContainsString('Municipal Manager', $escalation[0]->body);
        $this->assertNotNull($this->dispute->escalated_manager_on);

        // The manager gets the reply window too.
        $this->on(74);
        $this->assertNull($this->dispute->public_protector_draft_on);

        $this->on(75);
        $this->assertNotNull($this->dispute->public_protector_draft_on);
        Notification::assertSentTo($this->user, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'public_protector_draft'
            && str_contains(implode("\n", $n->lines), 'To: The Public Protector South Africa')
            && str_contains(implode("\n", $n->lines), 'Municipal Manager'));

        // Nothing is ever sent to the Public Protector by us.
        Mail::assertNotSent(DisputeEmail::class, fn (DisputeEmail $mail) => str_contains(implode(',', array_column($mail->to, 'address')), 'pprotect'));

        $this->on(90);
        // "Dispute sent", then the draft, once.
        Notification::assertSentToTimes($this->user, CustomerNotice::class, 2);
    }

    public function test_without_a_managers_address_the_draft_comes_when_the_councillor_window_ends(): void
    {
        $this->on(22);
        $this->on(53);

        $this->assertNull($this->dispute->escalated_manager_on);
        $this->assertNotNull($this->dispute->public_protector_draft_on);
    }

    public function test_while_the_customer_owes_information_we_remind_them_once_and_never_escalate(): void
    {
        $this->johannesburg->update(['escalation_email' => 'manager@joburg.example.org']);
        CarbonImmutable::setTestNow($this->dispute->lodged_on->addDays(5));
        app(DisputeService::class)->record($this->dispute, DisputeEventKind::InfoRequested, CarbonImmutable::today(), ['note' => 'a photo of the water meter']);

        $this->on(7);
        Notification::assertNotSentTo($this->user, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'info_reminder');

        $this->on(8);
        $this->on(9);
        $this->on(60);
        $this->on(120);

        $reminders = Notification::sent($this->user, CustomerNotice::class)->filter(fn (CustomerNotice $n) => $n->kind === 'info_reminder');
        $this->assertCount(1, $reminders);
        $this->assertStringContainsString('a photo of the water meter', $reminders->first()->lines[0]);
        $this->assertSame([], $this->emailsTo('manager@joburg.example.org'));
        $this->assertNull($this->dispute->public_protector_draft_on);
        $this->assertCount(1, $this->emailsTo('billing@joburg.example.org'), 'No follow-up either: they did answer.');
    }

    public function test_once_the_customer_answers_escalation_waits_a_full_window_from_that_day(): void
    {
        $this->johannesburg->update(['escalation_email' => 'manager@joburg.example.org']);
        CarbonImmutable::setTestNow($this->dispute->lodged_on->addDays(5));
        app(DisputeService::class)->record($this->dispute, DisputeEventKind::InfoRequested, CarbonImmutable::today(), ['note' => 'proof of payment']);

        // The customer says they sent it on day 40.
        CarbonImmutable::setTestNow($this->dispute->lodged_on->addDays(40));
        $this->actingAs($this->user)->post(route('disputes.information-sent', $this->dispute))->assertRedirect();
        $this->assertTrue($this->dispute->refresh()->info_answered_on->isToday());

        // From day 40: reply due day 61, councillor step day 62, window ends day 92.
        $this->on(53);
        $this->on(92);
        $this->assertSame([], $this->emailsTo('manager@joburg.example.org'));

        $this->on(93);
        $this->assertCount(1, $this->emailsTo('manager@joburg.example.org'));
    }

    public function test_the_customers_reply_on_the_thread_answers_the_request(): void
    {
        CarbonImmutable::setTestNow($this->dispute->lodged_on->addDays(5));
        app(DisputeService::class)->record($this->dispute, DisputeEventKind::InfoRequested, CarbonImmutable::today(), ['note' => 'a meter photo']);

        $this->post(route('inbound.email'), [
            'to' => $this->dispute->replyAddress(),
            'from' => $this->user->email,
            'subject' => 'RE: dispute',
            'body-plain' => 'Photo attached.',
            'Message-Id' => '<customer-1@example.com>',
        ], ['X-Inbound-Secret' => 'test-inbound-secret'])->assertStatus(202);

        $this->assertNotNull($this->dispute->refresh()->info_answered_on);
    }

    public function test_an_answer_stops_the_chasing(): void
    {
        app(DisputeService::class)->record($this->dispute, DisputeEventKind::Rejected, CarbonImmutable::today(), ['note' => 'Reading was actual.']);

        $this->on(60);

        $this->assertSame(1, $this->dispute->messages()->where('direction', DisputeMessage::OUT)->count());
        $this->assertNull($this->dispute->public_protector_draft_on);
    }
}
