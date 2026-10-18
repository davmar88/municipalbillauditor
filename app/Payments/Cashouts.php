<?php

namespace App\Payments;

use App\Game\Apples;
use App\Game\Clock;
use App\Game\GameException;
use App\Game\Notices;
use App\Game\Players;
use App\Models\AppleEntry;
use App\Models\Cashout;
use App\Models\PayoutAccount;
use App\Models\Player;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cashing Apples out for real money: into a South African bank account
 * through Paystack, or to a PayPal email. The Apples leave the animal's
 * balance when it asks, every cash-out waits for the owner's approval, and
 * the Apples come back when the owner turns it down or the payout fails. A
 * cash-out pays less per Apple than the cheapest pack costs, so every Apple
 * cashed out leaves the game ahead.
 *
 * A cash-out's reference goes to Paystack and PayPal with it, and both
 * refuse a second payout with the same reference. So when we don't hear
 * back, sending it again is safe: it can't pay twice.
 */
final class Cashouts
{
    public const METHODS = ['paystack', 'paypal'];

    /** The note on a Paystack transfer that waits for the owner's OTP. */
    public const OTP_NOTE = 'Paystack texted you an OTP for this transfer. Type it in to send the money, or turn off OTP for transfers in Paystack.';

    /** How Paystack says a transfer went nowhere. */
    private const PAYSTACK_FAILED = ['failed', 'reversed', 'abandoned', 'rejected', 'blocked'];

    /** How PayPal says a payout went nowhere, or came back. */
    private const PAYPAL_FAILED = ['FAILED', 'RETURNED', 'BLOCKED', 'REFUNDED', 'REVERSED', 'DENIED', 'CANCELED'];

    public function __construct(
        private readonly Settings $settings,
        private readonly Paystack $paystack,
        private readonly PayPal $paypal,
    ) {}

    /** Whether the owner has opened cash-outs. */
    public function open(): bool
    {
        return $this->settings->on('cashout_open');
    }

    /** @return array{paystack: bool, paypal: bool} The ways to be paid that are open. */
    public function ways(): array
    {
        $open = $this->open();

        return [
            'paystack' => $open && $this->paystack->ready(),
            'paypal' => $open && $this->paypal->ready(),
        ];
    }

    /**
     * The ways an animal can save payout details for. A PayPal email needs
     * no outside check, so it can be saved before cashing out opens; a bank
     * account is checked with the bank, which costs the game R3, so it waits
     * until bank transfers are open.
     *
     * @return array{paystack: bool, paypal: bool}
     */
    public function saveable(): array
    {
        return [
            'paystack' => $this->ways()['paystack'],
            'paypal' => true,
        ];
    }

    public static function currency(string $method): string
    {
        return $method === 'paypal' ? 'USD' : 'ZAR';
    }

    /** What cashing out this many Apples pays, in cents, rounded down. */
    public static function quote(int $apples, string $currency): int
    {
        return intdiv($apples * (int) config("game.cashout.per_100.{$currency}"), 100);
    }

    public static function min(): int
    {
        return (int) config('game.cashout.min');
    }

    public static function dailyCap(): int
    {
        return (int) config('game.cashout.daily_cap');
    }

    public static function holdDays(): int
    {
        return (int) config('game.cashout.hold_days');
    }

    /**
     * Apples the animal bought itself lately, by swapping Pineapples for
     * them or growing them on trees planted with Pineapples. They wait
     * before they can be cashed out, in case the card payment is reversed.
     */
    public static function held(Player $player): int
    {
        $bought = (int) AppleEntry::query()
            ->where('player_id', $player->id)
            ->where(fn ($query) => $query->where('reference', 'like', 'exchange:%')->orWhere('reference', 'like', 'tree:%')->orWhere('reference', 'like', 'purchase:%'))
            ->where('created_at', '>', now()->subDays(self::holdDays()))
            ->sum('amount');

        return max(0, min((int) $player->apples, $bought));
    }

    /** Apples asked for since the valley's midnight, leaving out ones that came back. */
    public static function today(Player $player): int
    {
        return (int) Cashout::query()
            ->where('player_id', $player->id)
            ->where('created_at', '>=', Clock::startOfToday())
            ->whereIn('status', ['pending', 'sending', 'paid'])
            ->sum('apples');
    }

    /** The most the animal can cash out right now. */
    public static function available(Player $player): int
    {
        return max(0, min((int) $player->apples - self::held($player), self::dailyCap() - self::today($player)));
    }

    /**
     * Where each animal's Apples came from over the last 30 days, for the
     * owner deciding whether to pay.
     *
     * @param  list<int>  $playerIds
     * @return array<int, array{bought: int, sales: int, bounties: int, returned: int, other: int}>
     */
    public static function sources(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        return AppleEntry::query()
            ->whereIn('player_id', $playerIds)
            ->where('amount', '>', 0)
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('player_id')
            ->selectRaw("player_id,
                SUM(amount) AS total,
                SUM(CASE WHEN reference LIKE 'exchange:%' OR reference LIKE 'tree:%' OR reference LIKE 'purchase:%' THEN amount ELSE 0 END) AS bought,
                SUM(CASE WHEN reference LIKE 'listing:%' THEN amount ELSE 0 END) AS sales,
                SUM(CASE WHEN reference LIKE 'bounty:%' AND reference NOT LIKE '%:back:%' THEN amount ELSE 0 END) AS bounties,
                SUM(CASE WHEN reference LIKE '%:back%' THEN amount ELSE 0 END) AS returned")
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->player_id => [
                'bought' => (int) $row->bought,
                'sales' => (int) $row->sales,
                'bounties' => (int) $row->bounties,
                'returned' => (int) $row->returned,
                'other' => (int) $row->total - (int) $row->bought - (int) $row->sales - (int) $row->bounties - (int) $row->returned,
            ]])
            ->all();
    }

    /**
     * Save where the animal wants its cash-outs sent, and who they are. A
     * bank account is checked with the bank first: it has to be in the
     * animal's own name and ID number.
     *
     * @param  array{legal_name: string, country: string, document_type: string, document_number: string, method: string, paypal_email?: string|null, bank_code?: string|null, account_number?: string|null}  $details
     *
     * @throws GameException
     */
    public function saveAccount(Player $player, array $details): PayoutAccount
    {
        $method = $details['method'];

        if (! ($this->saveable()[$method] ?? false)) {
            throw new GameException($this->open() ? self::closedWay($method) : 'Bank accounts can be added once cashing out opens. You can save your PayPal details now.');
        }

        $fields = [
            'legal_name' => Str::squish($details['legal_name']),
            'country' => $details['country'],
            'document_type' => $details['document_type'],
            'document_number' => Str::upper((string) preg_replace('/[\s-]+/', '', $details['document_number'])),
            'method' => $method,
            'paypal_email' => null,
            'bank_code' => null,
            'bank_name' => null,
            'account_number' => null,
            'recipient_code' => null,
            'verified_at' => null,
        ];

        if ($fields['document_type'] === 'id') {
            if ($fields['country'] !== 'ZA') {
                throw new GameException('A South African ID number is for animals living in South Africa. Use your passport number.');
            }

            self::checkIdNumber($fields['document_number']);
        } elseif (! preg_match('/^[A-Z0-9]{6,20}$/', $fields['document_number'])) {
            throw new GameException('A passport number is 6 to 20 letters and digits.');
        }

        if ($method === 'paypal') {
            $fields['paypal_email'] = Str::lower(trim((string) ($details['paypal_email'] ?? '')));
        } else {
            $fields = [...$fields, ...$this->bankAccount($player->payoutAccount()->first(), (int) $player->apples, $fields, (string) ($details['bank_code'] ?? ''), (string) ($details['account_number'] ?? ''))];
        }

        return PayoutAccount::query()->updateOrCreate(['player_id' => $player->id], $fields);
    }

    /**
     * Ask to cash Apples out. The Apples leave the animal's balance now and
     * wait for the owner, who approves every cash-out.
     *
     * @throws GameException
     */
    public function request(Player $player, int $apples): Cashout
    {
        if (! $this->open()) {
            throw new GameException("Cashing out isn't open yet.");
        }

        $account = $player->payoutAccount()->first() ?? throw new GameException('Add your payout details first.');

        if (! ($this->ways()[$account->method] ?? false)) {
            throw new GameException(self::closedWay($account->method).' Change your payout details to another way.');
        }

        if ($account->method === 'paystack' && $account->recipient_code === null) {
            throw new GameException('Save your bank details again, so the bank can check them.');
        }

        if ($apples < self::min()) {
            throw new GameException('Cash out at least '.number_format(self::min()).' Apples.');
        }

        return DB::transaction(function () use ($player, $apples, $account) {
            $player = Players::lock($player);

            if (Cashout::query()->where('player_id', $player->id)->whereIn('status', ['pending', 'sending'])->exists()) {
                throw new GameException('Your last cash-out is still on its way. Ask for the next one once it arrives.');
            }

            if ($apples > $player->apples) {
                throw new GameException('You have '.number_format($player->apples).' Apples.');
            }

            $held = self::held($player);

            if ($apples > $player->apples - $held) {
                throw new GameException(number_format($held).' of your Apples were bought in the last '.self::holdDays().' days, and bought Apples wait '.self::holdDays().' days before they can be cashed out. You can cash out '.number_format($player->apples - $held).' now.');
            }

            $left = max(0, self::dailyCap() - self::today($player));

            if ($apples > $left) {
                throw new GameException('You can cash out '.number_format(self::dailyCap()).' Apples a day. '.($left > 0 ? 'You can cash out '.number_format($left).' more today.' : 'Ask again tomorrow.'));
            }

            $currency = self::currency($account->method);
            $reference = 'rg-out-'.Str::lower((string) Str::ulid());

            $cashout = Cashout::create([
                'player_id' => $player->id,
                'reference' => $reference,
                'apples' => $apples,
                'method' => $account->method,
                'currency' => $currency,
                'amount' => self::quote($apples, $currency),
                'destination' => self::destination($account),
                'status' => 'pending',
            ]);

            Apples::spend($player, $apples, "Cashing out {$cashout->price()} to {$account->describe()}", "cashout:{$reference}");

            return $cashout;
        });
    }

    /**
     * The owner approves a cash-out: send it through Paystack or PayPal.
     *
     * @throws GameException
     */
    public function pay(Cashout $cashout, User $by): Cashout
    {
        if (! ($cashout->method === 'paystack' ? $this->paystack->ready() : $this->paypal->ready())) {
            throw new GameException($cashout->method === 'paystack' ? 'Add the Paystack secret key first.' : 'Add the PayPal client ID and secret first.');
        }

        // However often it's pressed, only one press sends it.
        $claimed = Cashout::query()->whereKey($cashout->id)->where('status', 'pending')->update([
            'status' => 'sending',
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
            'note' => null,
        ]);

        if ($claimed === 0) {
            throw new GameException('That cash-out was dealt with already.');
        }

        return $this->send($cashout->refresh());
    }

    /**
     * Ask Paystack or PayPal how a cash-out on its way stands.
     *
     * @throws GameException
     */
    public function check(Cashout $cashout): Cashout
    {
        if ($cashout->status !== 'sending') {
            throw new GameException('Only a cash-out on its way through Paystack or PayPal can be checked.');
        }

        try {
            if ($cashout->method === 'paystack') {
                $transfer = $this->paystack->transferStatus($cashout->reference);

                return $transfer === null
                    ? $this->back($cashout, 'Paystack has no record of it, so nothing was sent. Press Pay to send it again.')
                    : $this->settlePaystack($cashout, $transfer);
            }

            if ($cashout->provider_id === null) {
                // We never heard back. PayPal remembers a reference for 30
                // days, so within them sending it again can't pay twice.
                return $cashout->reviewed_at?->isAfter(now()->subDays(25))
                    ? $this->send($cashout)
                    : $this->note($cashout, 'PayPal never answered about this one. Look it up in PayPal by its reference before doing anything else.');
            }

            $payout = $this->paypal->payoutStatus($cashout->provider_id);
            $status = Str::upper((string) $payout['status']);

            return match (true) {
                $status === 'SUCCESS' => $this->paid($cashout),
                in_array($status, self::PAYPAL_FAILED, true) => $this->failed($cashout, $payout['error'] ?? 'PayPal says the payout was '.Str::lower($status).'.'),
                $status === 'UNCLAIMED' => $this->note($cashout, 'Waiting for '.($cashout->destination['email'] ?? 'the animal').' to claim it. PayPal sends it back after 30 days if nobody does.'),
                default => $this->note($cashout, 'PayPal is still sending it. Check again later.'),
            };
        } catch (PaymentException $e) {
            return $this->note($cashout, $e->getMessage());
        }
    }

    /**
     * Send a Paystack transfer that waits for the OTP Paystack texted the
     * owner.
     *
     * @throws GameException
     */
    public function otp(Cashout $cashout, string $otp): Cashout
    {
        if ($cashout->status !== 'sending' || $cashout->method !== 'paystack' || $cashout->provider_id === null) {
            throw new GameException('That cash-out is not waiting for an OTP.');
        }

        try {
            return $this->settlePaystack($cashout, $this->paystack->finalize($cashout->provider_id, $otp));
        } catch (PaymentRefused $e) {
            throw new GameException($e->getMessage());
        } catch (PaymentException $e) {
            return $this->note($cashout, $e->getMessage().' Press Check to see whether it went.');
        }
    }

    /**
     * Ask Paystack to text the owner a new OTP, when the first one ran out.
     *
     * @throws GameException
     */
    public function resendOtp(Cashout $cashout): void
    {
        if ($cashout->status !== 'sending' || $cashout->method !== 'paystack' || $cashout->provider_id === null) {
            throw new GameException('That cash-out is not waiting for an OTP.');
        }

        try {
            $this->paystack->resendOtp($cashout->provider_id);
        } catch (PaymentException $e) {
            throw new GameException($e->getMessage());
        }
    }

    /**
     * The owner turns a cash-out down: the Apples go back, and the animal
     * hears why.
     *
     * @throws GameException
     */
    public function reject(Cashout $cashout, User $by, string $reason): Cashout
    {
        return DB::transaction(function () use ($cashout, $by, $reason) {
            $done = Cashout::query()->whereKey($cashout->id)->where('status', 'pending')->update([
                'status' => 'rejected',
                'note' => Str::limit($reason, 250),
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'refunded_at' => now(),
            ]);

            if ($done === 0) {
                throw new GameException('That cash-out was dealt with already.');
            }

            $cashout->refresh();
            $this->giveBack($cashout, "Cash-out of {$cashout->price()} turned down");

            if ($cashout->player_id !== null) {
                Notices::send($cashout->player_id, 'apples', "Our team turned down your cash-out of {$cashout->price()}, so your {$cashout->apples} Apples are back. They said: {$reason}", route('play.apples', [], false));
            }

            return $cashout;
        });
    }

    /**
     * What Paystack's webhook says about a transfer.
     *
     * @param  array<string, mixed>  $data
     */
    public function settlePaystackTransfer(string $event, array $data): void
    {
        $reference = $data['reference'] ?? null;
        $cashout = is_string($reference) ? Cashout::query()->where('method', 'paystack')->where('reference', $reference)->first() : null;

        if ($cashout === null) {
            return;
        }

        if ($cashout->provider_id === null && is_string($data['transfer_code'] ?? null)) {
            $cashout->forceFill(['provider_id' => $data['transfer_code']])->save();
        }

        match ($event) {
            'transfer.success' => $this->paid($cashout),
            'transfer.failed' => $this->failed($cashout, (string) ($data['reason'] ?? $data['gateway_response'] ?? 'The bank turned the transfer down.')),
            // Money that arrived and was sent back.
            'transfer.reversed' => $this->failed($cashout, 'The bank sent the transfer back.', ['sending', 'paid']),
            default => null,
        };
    }

    /**
     * A South African ID number: 13 digits ending in a check digit, and
     * starting with a date of birth at least 18 years ago.
     *
     * @throws GameException
     */
    public static function checkIdNumber(string $number): void
    {
        if (! preg_match('/^\d{13}$/', $number) || ! self::luhn($number)) {
            throw new GameException("That isn't a South African ID number. Check its 13 digits.");
        }

        // "95" is 1995 and "05" is 2005: a year still to come is last century's.
        $year = 2000 + (int) substr($number, 0, 2);
        $year -= $year > Clock::now()->year ? 100 : 0;
        $month = (int) substr($number, 2, 2);
        $day = (int) substr($number, 4, 2);

        if (! checkdate($month, $day, $year)) {
            throw new GameException("That isn't a South African ID number. It starts with your date of birth.");
        }

        if (CarbonImmutable::create($year, $month, $day, 0, 0, 0, config('game.timezone'))->addYears(18)->isAfter(Clock::now())) {
            throw new GameException('Cashing out is for animals 18 and older.');
        }
    }

    /**
     * Check a bank account with the bank, and register it with Paystack to
     * pay into. Each check costs the game R3, so an account checked before
     * isn't checked again, and only an animal with Apples to cash out can
     * have one checked.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     *
     * @throws GameException
     */
    private function bankAccount(?PayoutAccount $saved, int $apples, array $fields, string $bankCode, string $accountNumber): array
    {
        if ($fields['country'] !== 'ZA') {
            throw new GameException('Bank transfers go to South African bank accounts. Pick PayPal instead.');
        }

        $bank = collect($this->paystack->banks())->firstWhere('code', $bankCode)
            ?? throw new GameException('Pick your bank from the list.');

        $checked = $saved !== null
            && $saved->method === 'paystack'
            && $saved->verified_at !== null
            && $saved->recipient_code !== null
            && $saved->bank_code === $bankCode
            && $saved->account_number === $accountNumber
            && $saved->legal_name === $fields['legal_name']
            && $saved->document_type === $fields['document_type']
            && $saved->document_number === $fields['document_number'];

        if ($checked) {
            return ['bank_code' => $bankCode, 'bank_name' => $bank['name'], 'account_number' => $accountNumber, 'recipient_code' => $saved->recipient_code, 'verified_at' => $saved->verified_at];
        }

        if ($apples < self::min()) {
            throw new GameException('You can add a bank account once you have '.number_format(self::min()).' Apples to cash out.');
        }

        try {
            if (! $this->paystack->validateAccount($fields['legal_name'], $accountNumber, $bankCode, $fields['document_type'], $fields['document_number'])) {
                throw new GameException("The bank says that account isn't in the name and ID number you gave. Check them, or pick another way to be paid.");
            }

            $recipient = $this->paystack->recipient($fields['legal_name'], $accountNumber, $bankCode);
        } catch (PaymentRefused $e) {
            Log::warning('Paystack could not check a payout account', ['message' => $e->getMessage()]);

            throw new GameException("The bank couldn't check that account. Check the bank and the account number, and try again.");
        } catch (PaymentException) {
            throw new GameException("Paystack didn't answer. Try again in a moment.");
        }

        return ['bank_code' => $bankCode, 'bank_name' => $bank['name'], 'account_number' => $accountNumber, 'recipient_code' => $recipient, 'verified_at' => now()];
    }

    /** Hand a cash-out marked as sending to Paystack or PayPal, and settle what it says. */
    private function send(Cashout $cashout): Cashout
    {
        try {
            if ($cashout->method === 'paystack') {
                return $this->settlePaystack($cashout, $this->paystack->transfer($cashout));
            }

            $cashout->forceFill(['provider_id' => $this->paypal->payout($cashout)])->save();

            return $this->note($cashout, 'PayPal took it. Press Check to see when it arrives.');
        } catch (PaymentRefused $e) {
            // Paystack refuses a reference it has seen: perhaps a transfer
            // that went before we heard back. Its record says.
            if ($cashout->method === 'paystack') {
                try {
                    $transfer = $this->paystack->transferStatus($cashout->reference);
                } catch (PaymentException) {
                    return $this->note($cashout, $e->getMessage().' Press Check to make sure nothing went.');
                }

                if ($transfer !== null) {
                    return $this->settlePaystack($cashout, $transfer);
                }
            }

            return $this->back($cashout, $e->getMessage());
        } catch (PaymentException $e) {
            return $this->note($cashout, $e->getMessage());
        }
    }

    /** @param  array{status: string, code: string|null, reason?: string|null}  $transfer */
    private function settlePaystack(Cashout $cashout, array $transfer): Cashout
    {
        if ($cashout->provider_id === null && $transfer['code'] !== null) {
            $cashout->forceFill(['provider_id' => $transfer['code']])->save();
        }

        $status = Str::lower($transfer['status']);

        return match (true) {
            $status === 'success' => $this->paid($cashout),
            in_array($status, self::PAYSTACK_FAILED, true) => $this->failed($cashout, $transfer['reason'] ?? "Paystack says the transfer {$status}."),
            $status === 'otp' => $this->note($cashout, self::OTP_NOTE),
            default => $this->note($cashout, 'Paystack is sending it. Press Check to see when it arrives.'),
        };
    }

    /** The money went: tell the animal, once. */
    private function paid(Cashout $cashout): Cashout
    {
        $done = Cashout::query()->whereKey($cashout->id)->whereIn('status', ['pending', 'sending'])->update([
            'status' => 'paid',
            'paid_at' => now(),
            'note' => null,
        ]);

        $cashout->refresh();

        if ($done === 1 && $cashout->player_id !== null) {
            Notices::send($cashout->player_id, 'apples', "💸 Your cash-out was paid: {$cashout->price()} for {$cashout->apples} Apples, to {$cashout->destination['label']}.", route('play.apples', [], false));
        } elseif ($done === 0 && $cashout->status !== 'paid') {
            Log::error('A provider says a cash-out was paid after it came back', ['cashout' => $cashout->id, 'status' => $cashout->status]);
        }

        return $cashout;
    }

    /**
     * The payout went nowhere, or came back: the Apples go back to the
     * animal, once.
     *
     * @param  list<string>  $from
     */
    private function failed(Cashout $cashout, string $why, array $from = ['sending']): Cashout
    {
        return DB::transaction(function () use ($cashout, $why, $from) {
            $done = Cashout::query()->whereKey($cashout->id)->whereIn('status', $from)->update([
                'status' => 'failed',
                'note' => Str::limit($why, 250),
                'refunded_at' => now(),
            ]);

            $cashout->refresh();

            if ($done === 1) {
                $this->giveBack($cashout, "Cash-out of {$cashout->price()} didn't go through");

                if ($cashout->player_id !== null) {
                    Notices::send($cashout->player_id, 'apples', "Your cash-out of {$cashout->price()} didn't go through, so your {$cashout->apples} Apples are back. Check your payout details.", route('play.apples', [], false));
                }
            }

            return $cashout;
        });
    }

    /** Nothing was sent: back in the owner's queue, with what went wrong. */
    private function back(Cashout $cashout, string $why): Cashout
    {
        Cashout::query()->whereKey($cashout->id)->where('status', 'sending')->update(['status' => 'pending', 'note' => Str::limit($why, 250)]);

        return $cashout->refresh();
    }

    private function note(Cashout $cashout, string $note): Cashout
    {
        $cashout->forceFill(['note' => Str::limit($note, 250)])->save();

        return $cashout;
    }

    private function giveBack(Cashout $cashout, string $reason): void
    {
        // A deleted account has nobody to give them back to.
        if ($cashout->player !== null) {
            Apples::award($cashout->player, $cashout->apples, $reason, "cashout:{$cashout->reference}:back");
        }
    }

    /** @return array<string, string> Where the money goes and who to, kept with the cash-out as it was asked for. */
    private static function destination(PayoutAccount $account): array
    {
        return [
            'name' => $account->legal_name,
            'label' => $account->describe(),
            'document' => ($account->document_type === 'id' ? 'SA ID ' : 'Passport ').$account->maskedDocument(),
            ...($account->method === 'paypal'
                ? ['email' => (string) $account->paypal_email]
                : ['recipient' => (string) $account->recipient_code]),
        ];
    }

    private static function closedWay(string $method): string
    {
        return $method === 'paystack' ? "Bank transfers aren't open right now." : "Cashing out to PayPal isn't open right now.";
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;

        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $value = (int) $digit * ($i % 2 === 1 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $sum % 10 === 0;
    }
}
