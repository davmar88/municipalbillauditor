<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\DisputeEventKind;
use App\Enums\Job;
use App\Enums\OrganisationKind;
use App\Mail\DisputeEmail;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\Municipality;
use App\Models\User;
use App\Notifications\CustomerNotice;
use App\Services\BillChecker;
use App\Services\Billing\Wallet;
use App\Services\BillIntake;
use App\Services\Disputes\DisputeApproval;
use App\Services\Disputes\DisputeMailer;
use App\Services\Disputes\RecipientFinder;
use App\Services\DisputeService;
use App\Services\Reading\BillReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FakeBillReader;
use Tests\TestCase;

/**
 * Disputes go to the municipality by email, from us, on the customer's
 * behalf, to the best address we have.
 */
class DisputeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        config(['auditor.inbound_email_domain' => 'mail.example.org', 'mail.from.address' => 'disputes@example.org']);
    }

    private function household(): User
    {
        return User::factory()->forKind(OrganisationKind::Household)->withCredit()->create();
    }

    /**
     * The sample bill, with its original file on the private disk.
     */
    private function flaggedBill(User $user): Bill
    {
        $bill = app(BillIntake::class)->createSample($user->organisation, $user);
        Storage::disk('local')->put('bills/1/bill.pdf', '%PDF');
        Storage::disk('local')->put('meter-photos/1/meter.jpg', 'JPEG');
        $bill->update(['file_path' => 'bills/1/bill.pdf', 'file_name' => 'bill.pdf', 'file_mime' => 'application/pdf', 'meter_photo_path' => 'meter-photos/1/meter.jpg']);

        return $bill;
    }

    private function johannesburg(): Municipality
    {
        return Municipality::firstWhere('slug', 'johannesburg');
    }

    public function test_nothing_is_sent_without_the_customers_mandate(): void
    {
        $user = $this->household();
        $bill = $this->flaggedBill($user);
        $this->johannesburg()->update(['billing_email' => 'billing@joburg.example.org', 'billing_email_verified_at' => now()]);

        $this->actingAs($user)->get(route('disputes.create', $bill))->assertInertia(fn (Assert $page) => $page
            ->where('hasMandate', false)
            ->where('recipient.email', 'billing@joburg.example.org'),
        );

        $this->post(route('disputes.store', $bill), ['letter_body' => 'Letter'])->assertSessionHasErrors('mandate');

        Mail::assertNothingSent();
        $this->assertNull(Dispute::sole()->submitted_on);
        $this->assertSame('100.00', $user->organisation->refresh()->credit_balance);
    }

    public function test_an_approved_dispute_is_emailed_with_the_bill_and_the_customer_copied_in(): void
    {
        $user = $this->household();
        $bill = $this->flaggedBill($user);
        $this->johannesburg()->update(['billing_email' => 'billing@joburg.example.org', 'billing_email_verified_at' => now()]);

        $this->actingAs($user)->post(route('disputes.store', $bill), ['letter_body' => 'My letter.', 'mandate' => '1'])->assertSessionHasNoErrors();

        $dispute = Dispute::sole();
        $this->assertNotNull($user->organisation->refresh()->dispute_mandate_at);
        $this->assertSame('billing@joburg.example.org', $dispute->sent_to);
        $this->assertSame(RecipientFinder::VERIFIED, $dispute->sent_to_source);
        $this->assertTrue($dispute->lodged_on->isToday());
        $this->assertMatchesRegularExpression('/^[a-z0-9]{24}$/', $dispute->reply_token);

        Mail::assertSent(DisputeEmail::class, function (DisputeEmail $mail) use ($dispute, $user) {
            return $mail->hasTo('billing@joburg.example.org')
                && $mail->hasCc($user->email)
                && $mail->hasReplyTo("d-{$dispute->reply_token}@mail.example.org")
                && $mail->hasSubject($dispute->letter_subject)
                && str_contains($mail->body, 'on behalf of '.$user->name)
                && str_contains($mail->body, 'My letter.')
                && count($mail->attachments()) === 2;
        });

        $lodged = $dispute->events()->where('kind', DisputeEventKind::Lodged)->sole();
        $this->assertSame('Emailed to billing@joburg.example.org', $lodged->note);
        $this->assertSame(DisputeMessage::OUT, $dispute->messages()->sole()->direction);
        Notification::assertSentTo($user, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'dispute_sent');
    }

    public function test_the_address_comes_from_staff_then_the_bill_then_the_directory(): void
    {
        $user = $this->household();
        $bill = $this->flaggedBill($user);
        $dispute = Dispute::factory()->for($bill)->create();
        $finder = new RecipientFinder;
        $johannesburg = $this->johannesburg();

        $this->assertNull($finder->for($dispute->refresh()));

        $johannesburg->update(['billing_email' => 'directory@joburg.example.org']);
        $this->assertSame(['email' => 'directory@joburg.example.org', 'source' => RecipientFinder::DIRECTORY], $finder->for($dispute->refresh()));

        $bill->update(['municipality_email' => 'printed@joburg.example.org']);
        $this->assertSame(['email' => 'printed@joburg.example.org', 'source' => RecipientFinder::BILL], $finder->for($dispute->refresh()));

        $johannesburg->update(['billing_email' => 'verified@joburg.example.org', 'billing_email_verified_at' => now()]);
        $this->assertSame(['email' => 'verified@joburg.example.org', 'source' => RecipientFinder::VERIFIED], $finder->for($dispute->refresh()));
    }

    public function test_without_an_address_the_dispute_is_held_and_staff_send_it_later(): void
    {
        $user = $this->household();
        $staff = User::factory()->staff()->create();
        $bill = $this->flaggedBill($user);

        $this->actingAs($user)->post(route('disputes.store', $bill), ['letter_body' => 'Letter', 'mandate' => '1']);

        $dispute = Dispute::sole();
        Mail::assertNothingSent();
        $this->assertNull($dispute->lodged_on);
        $this->assertSame('no_address', $dispute->held_reason);
        Notification::assertSentTo($user, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'dispute_held');
        Notification::assertSentTo($staff, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'staff_dispute_held');

        $this->actingAs($staff)->get(route('staff.index'))->assertInertia(fn (Assert $page) => $page
            ->where('toLodge.0.heldReason', 'no_address')
            ->where('toLodge.0.recipient', null),
        );

        $this->johannesburg()->update(['billing_email' => 'billing@joburg.example.org', 'billing_email_verified_at' => now()]);
        $this->post(route('staff.disputes.send', $dispute))->assertRedirect();

        Mail::assertSent(DisputeEmail::class, fn (DisputeEmail $mail) => $mail->hasTo('billing@joburg.example.org'));
        $this->assertNull($dispute->refresh()->held_reason);
        $this->assertNotNull($dispute->lodged_on);
    }

    public function test_disputes_go_by_themselves_when_the_customer_asked_and_every_finding_is_likely(): void
    {
        $user = $this->household();
        $this->johannesburg()->update(['billing_email' => 'billing@joburg.example.org', 'billing_email_verified_at' => now()]);
        $user->organisation->forceFill(['dispute_mandate_at' => now(), 'auto_send_disputes' => true])->save();
        app(BillIntake::class)->homeFor($user->organisation, $this->johannesburg());

        // A bill with an estimated reading far above the usual, after five
        // actual ones: one likely error.
        $this->flaggedBill($user);
        Dispute::query()->delete();
        $reader = new FakeBillReader(FakeBillReader::output([
            'bill_date' => now()->subDays(2)->toDateString(),
            'period_start' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'account_number' => 'SAMPLE-0001',
            'meter_readings' => [['service' => 'water', 'meter_number' => '07-118843', 'previous' => null, 'current' => null, 'consumption' => 40, 'unit' => 'kL', 'reading_type' => 'estimated']],
            'charges' => [['service' => 'water', 'description' => 'Water 40 kL', 'amount' => 1680.0, 'quantity' => 40, 'unit' => 'kL']],
            'total_due' => 1680.0,
        ]));
        $this->app->instance(BillReader::class, $reader);

        $this->actingAs($user)->post(route('bills.store'), [
            'bill' => UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf'),
            'property_id' => Bill::latest('id')->value('property_id'),
        ]);

        $dispute = Dispute::sole();
        $this->assertNotNull($dispute->lodged_on);
        Mail::assertSent(DisputeEmail::class, 1);
    }

    public function test_a_mail_failure_during_auto_send_leaves_the_bill_checked_and_holds_the_dispute(): void
    {
        $user = $this->household();
        $staff = User::factory()->staff()->create();
        $this->johannesburg()->update(['billing_email' => 'billing@joburg.example.org', 'billing_email_verified_at' => now()]);
        $user->organisation->forceFill(['dispute_mandate_at' => now(), 'auto_send_disputes' => true])->save();
        $bill = $this->flaggedBill($user);
        Dispute::query()->delete();

        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused'));
        app(BillChecker::class)->check($bill->refresh());

        $this->assertSame(BillStatus::Checked, $bill->refresh()->status);
        $this->assertTrue(app(Wallet::class)->hasPaid(Job::BillCheck, $bill));

        $dispute = Dispute::sole();
        $this->assertNull($dispute->lodged_on);
        $this->assertSame(DisputeMailer::SEND_FAILED, $dispute->held_reason);
        $this->assertTrue(app(Wallet::class)->hasPaid(Job::Dispute, $dispute));
        Notification::assertSentTo($staff, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'staff_dispute_held');
    }

    public function test_a_dispute_is_emailed_once_however_often_it_is_sent(): void
    {
        $user = $this->household();
        $bill = $this->flaggedBill($user);
        $this->johannesburg()->update(['billing_email' => 'billing@joburg.example.org', 'billing_email_verified_at' => now()]);
        $user->organisation->forceFill(['dispute_mandate_at' => now()])->save();
        $dispute = app(DisputeService::class)->draft($bill, $user);
        app(DisputeService::class)->submit($dispute, $user);

        $mailer = app(DisputeMailer::class);
        $mailer->send($dispute);
        $mailer->send($dispute);

        // Even if the lodged date were lost, the copy of the email we sent
        // stops a second one.
        $dispute->refresh()->forceFill(['lodged_on' => null])->save();
        $mailer->send($dispute);

        Mail::assertSent(DisputeEmail::class, 1);
    }

    public function test_a_question_waiting_on_the_customer_stops_automatic_sending(): void
    {
        $user = $this->household();
        $user->organisation->forceFill(['dispute_mandate_at' => now(), 'auto_send_disputes' => true])->save();
        $bill = $this->flaggedBill($user);
        $bill->findings()->first()->update(['confidence' => 'question', 'question' => 'Was the water off?', 'answer_options' => [['yes', 'Yes']]]);

        $this->assertNull(app(DisputeApproval::class)->autoSend($bill->refresh()));
        Mail::assertNothingSent();
    }

    public function test_customers_switch_sending_for_them_on_and_off(): void
    {
        $user = $this->household();

        $this->actingAs($user)->patch(route('account.disputes.update'), ['mandate' => '1', 'auto_send' => '1']);
        $organisation = $user->organisation->refresh();
        $this->assertNotNull($organisation->dispute_mandate_at);
        $this->assertSame($user->id, $organisation->dispute_mandate_user_id);
        $this->assertTrue($organisation->auto_send_disputes);

        // Withdrawing the mandate switches automatic sending off too.
        $this->patch(route('account.disputes.update'), ['mandate' => '0', 'auto_send' => '1']);
        $this->assertNull($organisation->refresh()->dispute_mandate_at);
        $this->assertFalse($organisation->auto_send_disputes);
    }
}
