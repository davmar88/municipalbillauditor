<?php

namespace App\Payments;

use App\Game\GameException;
use App\Game\Notices;
use App\Game\Pineapples;
use App\Models\ApplePurchase;
use App\Models\Player;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Buying a pack of Pineapples: open a PayPal payment in US dollars, then hand
 * the Pineapples over once PayPal confirms the money arrived, however often
 * it is asked. The Pineapples are paid out once, and swap for packs of
 * Apples.
 */
final class Checkout
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PayPal $paypal,
    ) {}

    /** Whether the owner has opened sales. */
    public function onSale(): bool
    {
        return $this->settings->on('apples_on_sale');
    }

    /** Whether players can pay: sales are on and the PayPal keys are in. */
    public function open(): bool
    {
        return $this->onSale() && $this->paypal->ready();
    }

    /**
     * Start buying a pack, and return PayPal's page to send the player to.
     *
     * @throws GameException
     */
    public function start(Player $player, string $packId): string
    {
        if (! $this->open()) {
            throw new GameException($this->onSale() ? "Paying with PayPal isn't open yet." : "Pineapples aren't on sale yet.");
        }

        $pack = Packs::findPineapples($packId) ?? throw new GameException('Pick one of the packs.');

        $purchase = ApplePurchase::create([
            'player_id' => $player->id,
            'pack' => $pack['id'],
            'pineapples' => $pack['pineapples'],
            'provider' => 'paypal',
            'currency' => 'USD',
            'amount' => $pack['usd'],
            'reference' => 'rg-'.Str::lower((string) Str::ulid()),
            'status' => 'pending',
        ]);

        try {
            $order = $this->paypal->start($purchase, route('play.apples.paypal'), route('play.apples.paypal-cancel'));
            $purchase->forceFill(['provider_id' => $order['id']])->save();

            return $order['url'];
        } catch (PaymentException $e) {
            $purchase->forceFill(['status' => 'failed'])->save();

            throw new GameException($e->getMessage());
        }
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
                ? $this->credit($purchase)
                : $this->review($purchase, $capture),
            'DECLINED', 'FAILED' => $this->fail($purchase),
            default => $purchase,
        };
    }

    /** The money arrived: hand the Pineapples over, once. */
    private function credit(ApplePurchase $purchase): ApplePurchase
    {
        return DB::transaction(function () use ($purchase) {
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

            $purchase->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            $pineapples = number_format($purchase->pineapples);
            Pineapples::award($purchase->player, $purchase->pineapples, "Bought {$pineapples} Pineapples", "purchase:{$purchase->id}");
            Notices::send($purchase->player_id, 'apples', "🍍 Your {$pineapples} Pineapples arrived. Swap them for Apples on the Apples page.", route('play.apples', [], false));

            return $purchase;
        });
    }

    private function fail(ApplePurchase $purchase): ApplePurchase
    {
        ApplePurchase::query()->whereKey($purchase->id)->where('status', 'pending')->update(['status' => 'failed']);

        return $purchase->refresh();
    }

    /**
     * Money arrived that doesn't match the pack. Nobody gets Pineapples for it
     * until the owner looks.
     *
     * @param  array<string, mixed>  $payment
     */
    private function review(ApplePurchase $purchase, array $payment): ApplePurchase
    {
        Log::error('A payment did not match its Pineapple pack', [
            'purchase' => $purchase->id,
            'expected' => [$purchase->amount, $purchase->currency],
            'got' => [$payment['amount'] ?? null, $payment['currency'] ?? null],
        ]);

        ApplePurchase::query()->whereKey($purchase->id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'review']);

        return $purchase->refresh();
    }
}
