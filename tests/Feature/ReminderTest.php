<?php

namespace Tests\Feature;

use App\Enums\DisputeMode;
use App\Enums\OrganisationKind;
use App\Models\Bill;
use App\Models\User;
use App\Notifications\DisputeReminder;
use App\Services\BillIntake;
use App\Services\DisputeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->user = User::factory()->forKind(OrganisationKind::Household)->create();
        $this->bill = app(BillIntake::class)->createSample($this->user->organisation, $this->user);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function on(CarbonImmutable $day): void
    {
        CarbonImmutable::setTestNow($day);
        $this->artisan('disputes:remind')->assertSuccessful();
    }

    public function test_customers_hear_before_the_dispute_window_closes_and_only_once(): void
    {
        $deadline = $this->bill->bill_date->addDays(30);

        $this->on($deadline->subDays(10));
        Notification::assertNothingSent();

        $this->on($deadline->subDays(7));
        $this->on($deadline->subDays(6));
        Notification::assertSentToTimes($this->user, DisputeReminder::class, 1);

        $this->on($deadline->subDays(2));
        Notification::assertSentToTimes($this->user, DisputeReminder::class, 2);

        Notification::assertSentTo($this->user, DisputeReminder::class, function (DisputeReminder $n) {
            $mail = $n->toMail($this->user);

            return $n->kind === 'window_closing'
                && str_contains($mail->subject, 'Dispute your')
                && str_contains(implode(' ', $mail->introLines), 'R924.00');
        });
    }

    public function test_a_disputed_bill_gets_no_window_reminder(): void
    {
        $disputes = app(DisputeService::class);
        $disputes->submit($disputes->draft($this->bill, $this->user), DisputeMode::Managed, $this->user);

        $this->on($this->bill->bill_date->addDays(23));

        Notification::assertNothingSent();
    }

    public function test_a_self_sent_dispute_with_no_reply_is_escalated_by_its_owner(): void
    {
        $disputes = app(DisputeService::class);
        $dispute = $disputes->submit($disputes->draft($this->bill, $this->user), DisputeMode::Self, $this->user);

        $this->on($dispute->lodged_on->addDays(21));
        Notification::assertNothingSent();

        $this->on($dispute->lodged_on->addDays(22));
        $this->on($dispute->lodged_on->addDays(23));

        Notification::assertSentToTimes($this->user, DisputeReminder::class, 1);
        Notification::assertSentTo($this->user, DisputeReminder::class, fn (DisputeReminder $n) => $n->kind === 'escalate_councillor');
    }
}
