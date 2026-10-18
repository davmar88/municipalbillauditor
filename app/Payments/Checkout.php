<?php

namespace App\Payments;

use App\Game\Apples;
use App\Game\GameException;
use App\Game\Notices;
use App\Models\ApplePurchase;
use App\Models\Player;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Buying a pack of Apples: open a payment with Paystack (rand) or PayPal
 * (dollars), then hand the Apples over once the provider confirms the money
 * arrived, whichever way the news comes and however often. The Apples are
 * paid out once.
 */
final class Checkout
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Paystack $paystack,
        private readonly PayPal $paypal,
    ) {}

    /** Whether the owner has opened Apple sales. */
    public function onSale(): bool
    {
        return $this->settings->on('apples_on_sale');
    }

    /** @return array{paystack: bool, paypal: bool} The ways to pay that are open. */
    public function ways(): array
    {
        $open = $this->onSale();

        return [
            'paystack' => $open && $this->paystack->ready(),
            'paypal' => $open && $this->paypal->ready(),
        ];
    }

    /**
     * Start buying a pack, and return the provider's page to send the
     * player to.
     *
     * @throws GameException
     */
    public function start(Player $player, string $packId, string $via): string
    {
        if (! ($this->ways()[$via] ?? false)) {
            throw new GameException(match (true) {
                ! $this->onSale() => "Apple packs aren't on sale yet.",
                $via === 'paypal' => "Paying with PayPal isn't open yet.",
                default => "Paying with Paystack isn't open yet.",
            });
        }

        $pack = Packs::find($packId) ?? throw new GameException('Pick one of the packs.');
        $rand = $via === 'paystack';

        $purchase = ApplePurchase::create([
            'player_id' => $player->id,
            'pack' => $pack['id'],
            'apples' => $pack['apples'],
            'provider' => $via,
            'currency' => $rand ? 'ZAR' : 'USD',
            'amount' => $rand ? $pack['zar'] : $pack['usd'],
            'reference' => 'rg-'.Str::lower((string) Str::ulid()),
            'status' => 'pending',
        ]);

        try {
            if ($rand) {
                return $this->paystack->start($purchase, $player->user->email, route('play.apples.paystack'));
            }

            $order = $this->paypal->start($purchase, route('play.apples.paypal'), route('play.apples.paypal-cancel'));
            $purchase->forceFill(['provider_id' => $order['id']])->save();

            return $order['url'];
        } catch (PaymentException $e) {
            $purchase->forceFill(['status' => 'failed'])->save();

            throw new GameException($e->getMessage());
        }
    }

    /**
     * Settle a Paystack payment: from what its webhook sent, or else by
     * asking Paystack.
     *
     * @param  array<string, mixed>|null  $transaction
     *
     * @throws PaymentException
     */
    public function settlePaystack(ApplePurchase $purchase, ?array $transaction = null): ApplePurchase
    {
        $transaction ??= $this->paystack->transaction($purchase->reference);

        if ($transaction === null || ($transaction['reference'] ?? null) !== $purchase->reference) {
            return $purchase;
        }

        return match ($transaction['status'] ?? null) {
            'success' => (int) ($transaction['amount'] ?? 0) === $purchase->amount && Str::upper((string) ($transaction['currency'] ?? '')) === $purchase->currency
                ? $this->credit($purchase, isset($transaction['id']) ? (string) $transaction['id'] : null)
                : $this->review($purchase, $transaction),
            'failed', 'abandoned', 'reversed' => $this->fail($purchase),
            default => $purchase,
        };
    }

    /**
     * Take the money for a PayPal order the player approved, and settle it.
     *
     * @throws PaymentException
     */
    public function settlePayPal(ApplePurchase $purchase): ApplePurchase
    {
        if ($purchase->status === 'paid' || $purchase->provider_id === null) {
            return $purchase;
        }

        $order = $this->paypal->capture($purchase->provider_id);
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

        if (! is_array($capture)) {
            return $purchase;
        }

        return match ($capture['status'] ?? null) {
            'COMPLETED' => ($capture['amount']['value'] ?? null) === PayPal::money($purchase->amount) && ($capture['amount']['currency_code'] ?? null) === $purchase->currency
                ? $this->credit($purchase, null)
                : $this->review($purchase, $capture),
            'DECLINED', 'FAILED' => $this->fail($purchase),
            default => $purchase,
        };
    }

    /** The money arrived: hand the Apples over, once. */
    private function credit(ApplePurchase $purchase, ?string $providerId): ApplePurchase
    {
        return DB::transaction(function () use ($purchase, $providerId) {
            $purchase = ApplePurchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status === 'paid') {
                return $purchase;
            }

            // The account was deleted after paying: the owner refunds it by hand.
            if ($purchase->player === null) {
                Log::warning('A payment arrived for a deleted account', ['purchase' => $purchase->id]);
                $purchase->forceFill(['status' => 'review'])->save();

                return $purchase;
            }

            $purchase->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'provider_id' => $purchase->provider_id ?? $providerId,
            ])->save();

            Apples::award($purchase->player, $purchase->apples, "Bought {$purchase->apples} Apples", "purchase:{$purchase->id}");
            Notices::send($purchase->player_id, 'apples', "🍎 Your {$purchase->apples} Apples arrived. Thank you!", route('play.apples', [], false));

            return $purchase;
        });
    }

    private function fail(ApplePurchase $purchase): ApplePurchase
    {
        ApplePurchase::query()->whereKey($purchase->id)->where('status', 'pending')->update(['status' => 'failed']);

        return $purchase->refresh();
    }

    /**
     * Money arrived that doesn't match the pack. Nobody gets Apples for it
     * until the owner looks.
     *
     * @param  array<string, mixed>  $payment
     */
    private function review(ApplePurchase $purchase, array $payment): ApplePurchase
    {
        Log::error('A payment did not match its Apple pack', [
            'purchase' => $purchase->id,
            'expected' => [$purchase->amount, $purchase->currency],
            'got' => [$payment['amount'] ?? null, $payment['currency'] ?? null],
        ]);

        ApplePurchase::query()->whereKey($purchase->id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'review']);

        return $purchase->refresh();
    }
}
