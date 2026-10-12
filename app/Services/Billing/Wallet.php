<?php

namespace App\Services\Billing;

use App\Audit\Money;
use App\Enums\CreditKind;
use App\Enums\Job;
use App\Jobs\ResumeWaitingWork;
use App\Models\CreditTransaction;
use App\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An organisation's prepaid credit.
 *
 * Every change is a row in the credit_transactions ledger, written in the
 * same database transaction as the cached balance on the organisation, with
 * the organisation's row locked. So two jobs running at once can't both
 * spend the same rand, and the balance always equals the ledger.
 *
 * Each row carries a unique reference. A bill or dispute is charged at most
 * once (a second charge returns the first), a payment is credited at most
 * once, and a refund is made at most once per charge.
 */
class Wallet
{
    public function balance(Organisation $organisation): string
    {
        return (string) Money::money((string) Organisation::query()->whereKey($organisation->id)->value('credit_balance'));
    }

    /**
     * What a job would cost this organisation right now: nothing when it is
     * included in a monthly fee, otherwise the household price.
     */
    public function quote(Organisation $organisation, Job $job): JobQuote
    {
        $covered = $this->coveredByPlan($organisation, $job);
        $price = $covered ? '0.00' : $job->price();
        $balance = $this->balance($organisation);

        return new JobQuote(
            job: $job,
            price: $price,
            coveredByPlan: $covered,
            balance: $balance,
            affordable: Money::of($balance)->isGreaterThanOrEqualTo($price),
        );
    }

    /**
     * Pay for a job on a bill or dispute. Charging the same subject again
     * returns the earlier charge, unless that charge was refunded.
     *
     * @throws InsufficientCredit
     */
    public function charge(Organisation $organisation, Job $job, Model $subject, ?User $by = null): CreditTransaction
    {
        return DB::transaction(function () use ($organisation, $job, $subject, $by) {
            $locked = $this->lock($organisation);
            $charges = $this->rows($locked, $job, $subject, CreditKind::Job);
            $refunds = $this->rows($locked, $job, $subject, CreditKind::Refund);

            if ($charges->count() > $refunds->count()) {
                return $charges->last();
            }

            $covered = $this->coveredByPlan($locked, $job);
            $price = $covered ? Money::money(0) : Money::of($job->price());
            $balance = Money::of($locked->credit_balance);

            if ($balance->isLessThan($price)) {
                throw new InsufficientCredit($job, (string) $price, (string) $balance);
            }

            return $this->write($locked, [
                'kind' => CreditKind::Job,
                'job' => $job,
                'amount' => (string) $price->negated(),
                'covered_by_plan' => $covered,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'user_id' => $by?->id,
                'description' => $job->label().($covered ? ', included in your monthly plan' : ''),
                'reference' => self::subjectReference($job, $subject).':'.($charges->count() + 1),
            ]);
        });
    }

    /**
     * Give back a job's charge, for example when a bill couldn't be read.
     * Returns null when there is no charge left to refund.
     */
    public function refund(Organisation $organisation, Job $job, Model $subject, string $reason): ?CreditTransaction
    {
        return DB::transaction(function () use ($organisation, $job, $subject, $reason) {
            $locked = $this->lock($organisation);
            $charges = $this->rows($locked, $job, $subject, CreditKind::Job);
            $refunds = $this->rows($locked, $job, $subject, CreditKind::Refund);

            if ($charges->count() <= $refunds->count()) {
                return null;
            }

            /** @var CreditTransaction $charge */
            $charge = $charges->last();

            return $this->write($locked, [
                'kind' => CreditKind::Refund,
                'job' => $job,
                'amount' => (string) Money::of($charge->amount)->negated(),
                'covered_by_plan' => $charge->covered_by_plan,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'description' => $reason,
                'reference' => "refund:{$charge->reference}",
            ]);
        });
    }

    /**
     * Add credit: a top-up, an EFT staff recorded, or the welcome credit.
     * The reference makes it happen once, so a payment notified twice is
     * credited once. Bills waiting for credit are then checked.
     */
    public function credit(
        Organisation $organisation,
        CreditKind $kind,
        string $amount,
        string $reference,
        string $description,
        ?Model $subject = null,
        ?User $by = null,
    ): CreditTransaction {
        $amount = Money::money($amount);

        if (! in_array($kind, [CreditKind::TopUp, CreditKind::Welcome, CreditKind::Adjustment], true)) {
            throw new InvalidArgumentException("Credit can't be added as {$kind->value}.");
        }

        $transaction = DB::transaction(function () use ($organisation, $kind, $amount, $reference, $description, $subject, $by) {
            $locked = $this->lock($organisation);
            $existing = CreditTransaction::query()->where('reference', $reference)->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($kind === CreditKind::Adjustment && Money::of($locked->credit_balance)->plus($amount)->isNegative()) {
                throw new InvalidArgumentException('That would take the balance below zero.');
            }

            return $this->write($locked, [
                'kind' => $kind,
                'amount' => (string) $amount,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'user_id' => $by?->id,
                'description' => $description,
                'reference' => $reference,
            ]);
        });

        if ($transaction->wasRecentlyCreated && $amount->isPositive()) {
            ResumeWaitingWork::dispatch($organisation->id)->afterCommit();
        }

        return $transaction;
    }

    public function welcome(Organisation $organisation): void
    {
        $amount = Money::money((string) config('auditor.prices.welcome_credit'));

        if ($amount->isPositive()) {
            $this->credit($organisation, CreditKind::Welcome, (string) $amount, "welcome:{$organisation->id}", 'Welcome credit');
        }
    }

    public function hasPaid(Job $job, Model $subject): bool
    {
        $rows = CreditTransaction::query()
            ->where('job', $job)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());

        return (clone $rows)->where('kind', CreditKind::Job)->count() > (clone $rows)->where('kind', CreditKind::Refund)->count();
    }

    /**
     * How many of this month's included bill checks are used. Null when the
     * organisation has no monthly plan.
     */
    public function includedChecksUsed(Organisation $organisation, ?CarbonImmutable $month = null): ?int
    {
        if (! $organisation->paysMonthly()) {
            return null;
        }

        $month ??= CarbonImmutable::now();
        $rows = CreditTransaction::query()
            ->where('organisation_id', $organisation->id)
            ->where('job', Job::BillCheck)
            ->where('covered_by_plan', true)
            ->whereBetween('created_at', [$month->startOfMonth(), $month->endOfMonth()]);

        return (clone $rows)->where('kind', CreditKind::Job)->count() - (clone $rows)->where('kind', CreditKind::Refund)->count();
    }

    public static function includedChecks(): int
    {
        return (int) config('auditor.prices.included_bill_checks');
    }

    private function coveredByPlan(Organisation $organisation, Job $job): bool
    {
        if (! $organisation->paysMonthly()) {
            return false;
        }

        // Disputes are unlimited on a monthly plan; bill checks up to the
        // month's allowance.
        return $job === Job::Dispute || $this->includedChecksUsed($organisation) < self::includedChecks();
    }

    private function lock(Organisation $organisation): Organisation
    {
        return Organisation::query()->lockForUpdate()->findOrFail($organisation->id);
    }

    /**
     * @return Collection<int, CreditTransaction>
     */
    private function rows(Organisation $organisation, Job $job, Model $subject, CreditKind $kind)
    {
        return CreditTransaction::query()
            ->where('organisation_id', $organisation->id)
            ->where('kind', $kind)
            ->where('job', $job)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(Organisation $locked, array $attributes): CreditTransaction
    {
        $balance = Money::of($locked->credit_balance)->plus($attributes['amount']);
        $locked->forceFill(['credit_balance' => (string) $balance])->save();

        return CreditTransaction::create([
            ...$attributes,
            'organisation_id' => $locked->id,
            'balance_after' => (string) $balance,
        ]);
    }

    private static function subjectReference(Job $job, Model $subject): string
    {
        return $job->value.':'.strtolower(class_basename($subject)).':'.$subject->getKey();
    }
}
