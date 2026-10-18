<?php

namespace App\Http\Controllers\Game;

use App\Game\Apples;
use App\Game\Market;
use App\Game\Orchard;
use App\Http\Controllers\Controller;
use App\Models\AppleEntry;
use App\Models\ApplePurchase;
use App\Models\Player;
use App\Payments\Checkout;
use App\Payments\Packs;
use App\Payments\PaymentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The Apples page: buying packs with Paystack or PayPal, coming back from
 * paying, swapping Apples for Corn, Orchard membership, and the animal's
 * Apple history.
 */
class AppleController extends Controller
{
    public function index(Request $request, Checkout $checkout): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;

        return Inertia::render('game/apples', [
            'packs' => Packs::all(),
            'onSale' => $checkout->onSale(),
            'ways' => $checkout->ways(),
            'cornPerApple' => Apples::cornPerApple(),
            'pending' => ApplePurchase::query()
                ->where('player_id', $player->id)
                ->whereIn('status', ['pending', 'review'])
                ->where('created_at', '>=', now()->subDay())
                ->latest('id')
                ->get()
                ->map(fn (ApplePurchase $purchase) => [
                    'id' => $purchase->id,
                    'apples' => $purchase->apples,
                    'price' => $purchase->price(),
                    'via' => $purchase->provider,
                    'review' => $purchase->status === 'review',
                    'at' => $purchase->created_at->toIso8601String(),
                ]),
            'history' => AppleEntry::query()
                ->where('player_id', $player->id)
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (AppleEntry $entry) => [
                    'id' => $entry->id,
                    'amount' => $entry->amount,
                    'reason' => $entry->reason,
                    'at' => $entry->created_at?->toIso8601String(),
                ]),
            'orchard' => [
                'price' => Orchard::price(),
                'days' => Orchard::days(),
                'until' => $player->inOrchard() ? $player->orchard_until->toIso8601String() : null,
                'refillReady' => Orchard::refillReady($player),
                'shelves' => Market::SHELVES + 1,
            ],
            'contact' => config('game.contact_email'),
        ]);
    }

    /** Off to Paystack or PayPal to pay for a pack. */
    public function buy(Request $request, Checkout $checkout): HttpResponse
    {
        $data = $request->validate([
            'pack' => ['required', 'string', 'max:32'],
            'via' => ['required', Rule::in(['paystack', 'paypal'])],
        ]);

        return Inertia::location($checkout->start($request->user()->player, $data['pack'], $data['via']));
    }

    /** Back from Paystack's page. */
    public function paystack(Request $request, Checkout $checkout): RedirectResponse
    {
        $purchase = $this->purchase($request, 'paystack', 'reference', (string) $request->query('reference', $request->query('trxref', '')));

        if ($purchase === null) {
            return $this->toApples("We couldn't find that payment.", 'error');
        }

        try {
            $purchase = $checkout->settlePaystack($purchase);
        } catch (PaymentException $e) {
            return $this->toApples($e->getMessage(), 'error');
        }

        return $this->settled($purchase, 'Paystack');
    }

    /** Back from PayPal, having approved the order: take the money. */
    public function paypal(Request $request, Checkout $checkout): RedirectResponse
    {
        $purchase = $this->purchase($request, 'paypal', 'provider_id', (string) $request->query('token', ''));

        if ($purchase === null) {
            return $this->toApples("We couldn't find that payment.", 'error');
        }

        try {
            $purchase = $checkout->settlePayPal($purchase);
        } catch (PaymentException $e) {
            return $this->toApples($e->getMessage(), 'error');
        }

        return $this->settled($purchase, 'PayPal');
    }

    /** Back from PayPal without paying. */
    public function paypalCancel(Request $request): RedirectResponse
    {
        $purchase = $this->purchase($request, 'paypal', 'provider_id', (string) $request->query('token', ''));

        if ($purchase?->status === 'pending') {
            $purchase->forceFill(['status' => 'failed'])->save();
        }

        return $this->toApples('You left PayPal without paying. Nothing was charged.');
    }

    /** Ask again about a payment that hadn't cleared. */
    public function check(Request $request, ApplePurchase $purchase, Checkout $checkout): RedirectResponse
    {
        abort_unless($purchase->player_id === $request->user()->player->id, 404);

        try {
            $purchase = $purchase->provider === 'paystack' ? $checkout->settlePaystack($purchase) : $checkout->settlePayPal($purchase);
        } catch (PaymentException $e) {
            return $this->toApples($e->getMessage(), 'error');
        }

        return $this->settled($purchase, $purchase->provider === 'paystack' ? 'Paystack' : 'PayPal');
    }

    public function swap(Request $request): RedirectResponse
    {
        $apples = (int) $request->validate(['apples' => ['required', 'integer', 'min:1', 'max:1000000']])['apples'];
        $corn = Apples::swapForCorn($request->user()->player, $apples);

        return $this->told('You swapped '.number_format($apples).' '.($apples === 1 ? 'Apple' : 'Apples').' for '.number_format($corn).' Corn.');
    }

    private function purchase(Request $request, string $provider, string $column, string $value): ?ApplePurchase
    {
        if ($value === '') {
            return null;
        }

        return ApplePurchase::query()
            ->where('provider', $provider)
            ->where($column, $value)
            ->where('player_id', $request->user()->player->id)
            ->first();
    }

    private function settled(ApplePurchase $purchase, string $provider): RedirectResponse
    {
        return match ($purchase->status) {
            'paid' => $this->toApples("Your {$purchase->apples} Apples arrived. Thank you!"),
            'failed' => $this->toApples("The payment didn't go through, so nothing was charged.", 'error'),
            'review' => $this->toApples("The payment didn't match the pack, so we're checking it by hand. Your Apples arrive once we have.", 'error'),
            default => $this->toApples("{$provider} is still confirming the payment. Your Apples arrive as soon as it does."),
        };
    }

    /**
     * To the Apples page with a toast. Not back(): coming from a payment
     * page, back would be the payment page.
     */
    private function toApples(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return redirect()->route('play.apples');
    }
}
