<?php

namespace App\Services\Disputes;

use App\Audit\Enums\Confidence;
use App\Enums\Job;
use App\Jobs\SendDispute;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\Finding;
use App\Models\User;
use App\Services\Billing\InsufficientCredit;
use App\Services\Billing\Wallet;
use App\Services\CustomerMail;
use App\Services\DisputeService;
use Illuminate\Validation\ValidationException;

/**
 * A dispute goes out when the customer approves it, or by itself when the
 * customer has asked us to send disputes for them and the bill has nothing
 * but likely errors on it.
 */
class DisputeApproval
{
    public function __construct(
        private readonly DisputeService $disputes,
        private readonly Wallet $wallet,
        private readonly CustomerMail $mail,
    ) {}

    /**
     * The customer approves and pays for the dispute; it is emailed straight
     * after, from the queue.
     * Ticking the mandate box records the customer's permission for this and
     * later disputes.
     *
     * @throws ValidationException
     */
    public function approve(Dispute $dispute, User $user, ?string $letterBody = null, bool $grantMandate = false): Dispute
    {
        $organisation = $dispute->organisation;

        if ($grantMandate && ! $organisation->hasMandate()) {
            $organisation->forceFill(['dispute_mandate_at' => now(), 'dispute_mandate_user_id' => $user->id])->save();
        }

        if (! $organisation->hasMandate()) {
            throw ValidationException::withMessages(['mandate' => 'Tick the box to let us send disputes about your account for you.']);
        }

        $this->disputes->submit($dispute, $user, $letterBody);
        SendDispute::dispatch($dispute)->afterCommit();

        return $dispute->refresh();
    }

    /**
     * After a bill is checked: send its dispute without asking, when the
     * customer has switched that on, has given us the mandate, and every
     * finding is a likely error with no question waiting for them.
     */
    public function autoSend(Bill $bill): ?Dispute
    {
        $organisation = $bill->organisation;

        if (! $organisation->auto_send_disputes || ! $organisation->hasMandate() || ! $bill->isChecked() || $bill->isLocked()) {
            return null;
        }

        $open = $bill->findings()->get()->filter(fn (Finding $f) => $f->isOpen());

        if ($open->isEmpty() || $open->contains(fn (Finding $f) => $f->confidence !== Confidence::Likely || $f->awaitingAnswer())) {
            return null;
        }

        $user = $bill->user ?? $organisation->users()->orderBy('users.id')->first();

        if ($user === null) {
            return null;
        }

        try {
            $dispute = $this->disputes->draft($bill, $user);
        } catch (ValidationException) {
            return null;
        }

        try {
            $this->disputes->submit($dispute, $user);
        } catch (ValidationException) {
            // Not enough credit: the customer approves it after topping up.
            $quote = $this->wallet->quote($organisation, Job::Dispute);
            $this->mail->disputeCreditNeeded($dispute, new InsufficientCredit(Job::Dispute, $quote->price, $quote->balance));

            return null;
        }

        SendDispute::dispatch($dispute)->afterCommit();

        return $dispute->refresh();
    }
}
