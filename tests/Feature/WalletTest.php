<?php

namespace Tests\Feature;

use App\Audit\Money;
use App\Enums\CreditKind;
use App\Enums\Job;
use App\Models\Bill;
use App\Models\CreditTransaction;
use App\Models\Organisation;
use App\Services\Billing\InsufficientCredit;
use App\Services\Billing\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prepaid credit: every rand in or out is a ledger row, and the cached
 * balance always matches it.
 */
class WalletTest extends TestCase
{
    use RefreshDatabase;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet = app(Wallet::class);
    }

    private function household(string $credit = '0'): Organisation
    {
        $organisation = Organisation::factory()->create();

        if (Money::of($credit)->isPositive()) {
            $this->wallet->credit($organisation, CreditKind::TopUp, $credit, "seed:{$organisation->id}", 'Top-up');
        }

        return $organisation;
    }

    private function billFor(Organisation $organisation): Bill
    {
        return Bill::factory()->for($organisation)->create(['property_id' => null]);
    }

    private function assertLedgerMatches(Organisation $organisation): void
    {
        $sum = Money::sum($organisation->creditTransactions()->pluck('amount')->map(fn ($a) => Money::of($a)));

        $this->assertSame((string) Money::money($sum), $this->wallet->balance($organisation));
    }

    public function test_a_job_is_charged_from_credit(): void
    {
        $organisation = $this->household('50');
        $bill = $this->billFor($organisation);

        $charge = $this->wallet->charge($organisation, Job::BillCheck, $bill);

        $this->assertSame('-15.00', $charge->amount);
        $this->assertSame('35.00', $charge->balance_after);
        $this->assertSame('35.00', $this->wallet->balance($organisation));
        $this->assertLedgerMatches($organisation);
    }

    public function test_a_job_without_enough_credit_is_refused_and_nothing_is_written(): void
    {
        $organisation = $this->household('10');

        try {
            $this->wallet->charge($organisation, Job::BillCheck, $this->billFor($organisation));
            $this->fail('Expected the charge to be refused.');
        } catch (InsufficientCredit $e) {
            $this->assertSame('15.00', $e->price);
            $this->assertSame('10.00', $e->balance);
        }

        $this->assertSame('10.00', $this->wallet->balance($organisation));
        $this->assertSame(1, $organisation->creditTransactions()->count());
    }

    public function test_a_bill_or_dispute_is_charged_at_most_once(): void
    {
        $organisation = $this->household('100');
        $bill = $this->billFor($organisation);

        $first = $this->wallet->charge($organisation, Job::BillCheck, $bill);
        $second = $this->wallet->charge($organisation, Job::BillCheck, $bill);

        $this->assertTrue($first->is($second));
        $this->assertSame('85.00', $this->wallet->balance($organisation));

        // The reference is unique in the database too, as a backstop.
        $this->expectException(QueryException::class);
        CreditTransaction::create([...$first->only(['organisation_id', 'kind', 'job', 'amount', 'balance_after', 'description', 'reference'])]);
    }

    public function test_a_refund_gives_the_charge_back_once_and_allows_a_new_charge(): void
    {
        $organisation = $this->household('20');
        $bill = $this->billFor($organisation);

        $this->wallet->charge($organisation, Job::BillCheck, $bill);
        $refund = $this->wallet->refund($organisation, Job::BillCheck, $bill, 'Could not read it');

        $this->assertSame('15.00', $refund->amount);
        $this->assertNull($this->wallet->refund($organisation, Job::BillCheck, $bill, 'Again'));
        $this->assertSame('20.00', $this->wallet->balance($organisation));
        $this->assertFalse($this->wallet->hasPaid(Job::BillCheck, $bill));

        // Typed in later, it is a new check.
        $this->wallet->charge($organisation, Job::BillCheck, $bill);
        $this->assertSame('5.00', $this->wallet->balance($organisation));
        $this->assertLedgerMatches($organisation);
    }

    public function test_jobs_running_at_once_cannot_overspend(): void
    {
        $organisation = $this->household('20');

        // Two workers each loaded the organisation while it still showed
        // R20. The balance is re-read under a row lock, so only the first
        // charge goes through.
        $workerOne = Organisation::find($organisation->id);
        $workerTwo = Organisation::find($organisation->id);

        $this->wallet->charge($workerOne, Job::BillCheck, $this->billFor($organisation));

        $this->expectException(InsufficientCredit::class);

        try {
            $this->wallet->charge($workerTwo, Job::BillCheck, $this->billFor($organisation));
        } finally {
            $this->assertSame('5.00', $this->wallet->balance($organisation));
            $this->assertLedgerMatches($organisation);
        }
    }

    public function test_a_payment_is_credited_once(): void
    {
        $organisation = $this->household();

        $this->wallet->credit($organisation, CreditKind::TopUp, '100', 'payment:abc', 'Card top-up');
        $this->wallet->credit($organisation, CreditKind::TopUp, '100', 'payment:abc', 'Card top-up');

        $this->assertSame('100.00', $this->wallet->balance($organisation));
    }

    public function test_monthly_plans_include_checks_then_charge_credit(): void
    {
        config(['auditor.prices.included_bill_checks' => 2]);
        $agent = Organisation::factory()->agent()->create();
        $this->wallet->credit($agent, CreditKind::TopUp, '20', 'seed', 'Top-up');

        $this->assertTrue($this->wallet->charge($agent, Job::BillCheck, $this->billFor($agent))->covered_by_plan);
        $this->assertTrue($this->wallet->charge($agent, Job::BillCheck, $this->billFor($agent))->covered_by_plan);
        $this->assertSame(2, $this->wallet->includedChecksUsed($agent));

        $third = $this->wallet->charge($agent, Job::BillCheck, $this->billFor($agent));
        $this->assertFalse($third->covered_by_plan);
        $this->assertSame('5.00', $this->wallet->balance($agent));

        // Disputes are unlimited.
        $dispute = $this->wallet->charge($agent, Job::Dispute, $this->billFor($agent));
        $this->assertSame('0.00', $dispute->amount);
        $this->assertSame('Included', $this->wallet->quote($agent, Job::Dispute)->toArray()['price']);
    }

    public function test_welcome_credit_is_given_once_when_configured(): void
    {
        config(['auditor.prices.welcome_credit' => '30']);

        $organisation = Organisation::factory()->create();
        $this->wallet->welcome($organisation);

        $this->assertSame('30.00', $this->wallet->balance($organisation));
    }
}
