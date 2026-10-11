<?php

namespace Tests\Unit\Audit;

use App\Audit\Deadlines\CaseClock;
use App\Audit\Deadlines\CaseFacts;
use App\Audit\Deadlines\MetroRules;
use App\Audit\Deadlines\Stage;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class CaseClockTest extends TestCase
{
    private MetroRules $rules;

    protected function setUp(): void
    {
        $this->rules = new MetroRules;
    }

    private function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    private function lodged(array $overrides = []): CaseFacts
    {
        return new CaseFacts(...[
            'billDate' => $this->day('2026-10-03'),
            'checkedOn' => $this->day('2026-10-04'),
            'findingsSummary' => '2 likely errors, up to R1,013.60',
            'lodgedOn' => $this->day('2026-10-11'),
            'managed' => true,
            ...$overrides,
        ]);
    }

    public function test_dates_match_the_design(): void
    {
        $this->assertSame('2026-11-02', CaseClock::disputeDeadline($this->day('2026-10-03'), $this->rules)->format('Y-m-d'));
        $this->assertSame('2026-11-01', CaseClock::replyDue($this->day('2026-10-11'), $this->rules)->format('Y-m-d'));
        $this->assertSame('2026-11-02', CaseClock::councillorEscalationOn($this->day('2026-10-11'), $this->rules)->format('Y-m-d'));
    }

    public function test_draft_counts_down_to_the_dispute_window(): void
    {
        $facts = new CaseFacts(billDate: $this->day('2026-10-03'));

        $status = CaseClock::status($facts, $this->rules, $this->day('2026-10-11'));

        $this->assertSame(Stage::Draft, $status->stage);
        $this->assertSame(22, $status->daysLeft);
        $this->assertSame('until the dispute window closes on 2 Nov', $status->countdownLabel);
    }

    public function test_late_draft_still_lets_you_lodge(): void
    {
        $status = CaseClock::status(new CaseFacts(billDate: $this->day('2026-08-03')), $this->rules, $this->day('2026-10-11'));

        $this->assertSame('Lodge as soon as you can', $status->headline);
        $this->assertLessThan(0, $status->daysLeft);
    }

    public function test_awaiting_reply_shows_the_tracker_countdown(): void
    {
        $status = CaseClock::status($this->lodged(), $this->rules, $this->day('2026-10-21'));

        $this->assertSame(Stage::AwaitingReply, $status->stage);
        $this->assertSame(11, $status->daysLeft);
        $this->assertSame('until a reply is due on 1 Nov', $status->countdownLabel);
        $this->assertEqualsWithDelta(10 / 21, $status->progress, 0.0001);
        $this->assertStringContainsString("We escalate to your ward councillor on 2 Nov. You don't need to do anything.", $status->note);
    }

    public function test_self_lodged_disputes_get_a_reminder_rather_than_a_promise(): void
    {
        $status = CaseClock::status($this->lodged(['managed' => false]), $this->rules, $this->day('2026-10-21'));

        $this->assertStringContainsString("We'll remind you to escalate", $status->note);
    }

    public function test_stage_moves_through_escalation(): void
    {
        $this->assertSame(Stage::ReplyOverdue, CaseClock::stage($this->lodged(), $this->rules, $this->day('2026-11-02')));

        $withCouncillor = $this->lodged(['escalatedCouncillorOn' => $this->day('2026-11-02')]);
        $this->assertSame(Stage::WithCouncillor, CaseClock::stage($withCouncillor, $this->rules, $this->day('2026-11-20')));
        $this->assertSame(Stage::CouncillorOverdue, CaseClock::stage($withCouncillor, $this->rules, $this->day('2026-12-03')));

        $withProtector = $this->lodged(['escalatedPublicProtectorOn' => $this->day('2026-12-05')]);
        $this->assertSame(Stage::WithPublicProtector, CaseClock::stage($withProtector, $this->rules, $this->day('2026-12-10')));

        $credited = $this->lodged(['creditOn' => $this->day('2026-11-05'), 'creditAmount' => BigDecimal::of('1013.60')]);
        $this->assertSame(Stage::Resolved, CaseClock::stage($credited, $this->rules, $this->day('2026-11-06')));
    }

    public function test_timeline_marks_what_is_done_current_and_only_if_needed(): void
    {
        $facts = $this->lodged(['acknowledgedOn' => $this->day('2026-10-13'), 'reference' => '8004 1123 77']);

        $steps = CaseClock::timeline($facts, $this->rules, $this->day('2026-10-21'));
        $states = array_combine(array_map(fn ($s) => $s->key, $steps), array_map(fn ($s) => $s->state, $steps));

        $this->assertSame([
            'checked' => 'done',
            'lodged' => 'done',
            'acknowledged' => 'done',
            'reply' => 'current',
            'councillor' => 'if_needed',
            'public_protector' => 'if_needed',
            'credit' => 'upcoming',
        ], $states);
        $this->assertSame('Reference 8004 1123 77', $steps[2]->detail);
        $this->assertSame("2 Nov, only if there's no reply", $steps[4]->detail);
    }

    public function test_resolved_timeline_drops_the_escalation_steps(): void
    {
        $facts = $this->lodged([
            'replyReceivedOn' => $this->day('2026-10-25'),
            'creditOn' => $this->day('2026-11-05'),
            'creditAmount' => BigDecimal::of('1013.60'),
        ]);

        $keys = array_map(fn ($s) => $s->key, CaseClock::timeline($facts, $this->rules, $this->day('2026-11-06')));

        $this->assertSame(['checked', 'lodged', 'reply', 'credit'], $keys);
    }

    public function test_a_corrected_tariff_counts_as_the_municipality_acting(): void
    {
        $facts = $this->lodged([
            'acknowledgedOn' => $this->day('2026-10-19'),
            'reference' => 'TSH-BC-20931',
            'tariffFixedOn' => $this->day('2026-11-03'),
            'monthlySaving' => BigDecimal::of('7500.00'),
        ]);

        $status = CaseClock::status($facts, $this->rules, $this->day('2026-12-15'));
        $keys = array_map(fn ($step) => $step->key, CaseClock::timeline($facts, $this->rules, $this->day('2026-12-15')));

        $this->assertSame(Stage::AwaitingCredit, $status->stage);
        $this->assertStringContainsString('tariff has been corrected', $status->note);
        $this->assertSame(['checked', 'lodged', 'acknowledged', 'tariff_fixed', 'credit'], $keys);
    }

    public function test_an_overdue_reply_makes_escalating_the_one_current_step(): void
    {
        $steps = CaseClock::timeline($this->lodged(), $this->rules, $this->day('2026-11-05'));
        $current = array_values(array_filter($steps, fn ($s) => $s->state === 'current'));

        $this->assertCount(1, $current);
        $this->assertSame('councillor', $current[0]->key);
        $this->assertSame('There was no reply, so our team writes to your councillor', $current[0]->detail);
    }
}
