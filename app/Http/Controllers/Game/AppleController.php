<?php

namespace App\Http\Controllers\Game;

use App\Game\Apples;
use App\Game\GameException;
use App\Game\Horseshoes;
use App\Game\Market;
use App\Game\Orchard;
use App\Game\Pineapples;
use App\Game\Trees;
use App\Http\Controllers\Controller;
use App\Models\AppleEntry;
use App\Models\ApplePurchase;
use App\Models\Player;
use App\Payments\Checkout;
use App\Payments\Packs;
use App\Payments\PaymentException;
use App\Payments\PayPal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The Apples page: buying Pineapples with PayPal, coming back from paying,
 * swapping Pineapples for packs of Apples, swapping Apples for Corn, Orchard
 * membership, and the animal's Apple history. Cashing out has a page of its
 * own.
 */
class AppleController extends Controller
{
    public function index(Request $request, Checkout $checkout, PayPal $paypal): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;

        return Inertia::render('game/apples', [
            'pineapplePacks' => Packs::pineapples(),
            'applePacks' => Packs::apples(),
            'trees' => Trees::page($player),
            'onSale' => $checkout->onSale(),
            // PayPal's buttons pay right here on the page.
            'paypal' => $checkout->open() ? ['clientId' => $paypal->clientId(), 'currency' => 'USD'] : null,
            'termsUrl' => route('terms'),
            'horseshoes' => [
                'packs' => collect(Horseshoes::packs())->map(fn (int $apples, int $count) => ['count' => $count, 'apples' => $apples])->values(),
                'saddlebag' => Horseshoes::saddlebag(),
            ],
            'open' => $checkout->open(),
            'cornPerApple' => Apples::cornPerApple(),
            'pending' => ApplePurchase::query()
                ->where('player_id', $player->id)
                ->where('provider', 'paypal')
                ->whereIn('status', ['pending', 'review'])
                ->where('created_at', '>=', now()->subDay())
                ->latest('id')
                ->get()
                ->map(fn (ApplePurchase $purchase) => [
                    'id' => $purchase->id,
                    'pineapples' => $purchase->pineapples,
                    'price' => $purchase->price(),
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

    /** Off to PayPal to pay for a pack. */
    public function buy(Request $request, Checkout $checkout): HttpResponse
    {
        $pack = (string) $request->validate(['pack' => ['required', 'string', 'max:32'], 'terms' => ['accepted']])['pack'];

        return Inertia::location($checkout->start($request->user()->player, $pack));
    }

    /** PayPal's buttons on the page ask for an order for a pack. */
    public function order(Request $request, Checkout $checkout): JsonResponse
    {
        $data = $request->validate([
            'pack' => ['required', 'string', 'max:32'],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'Tick the box to accept the terms and conditions first.',
        ]);
        $pack = (string) $data['pack'];

        try {
            return response()->json(['id' => $checkout->order($request->user()->player, $pack)['id']]);
        } catch (GameException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** The player approved the payment in PayPal's window on the page: take the money. */
    public function capture(Request $request, Checkout $checkout, string $order): JsonResponse
    {
        $purchase = $this->purchase($request, $order);

        if ($purchase === null) {
            return response()->json(['status' => 'failed', 'message' => "We couldn't find that payment."], 404);
        }

        try {
            $purchase = $checkout->settlePayPal($purchase);
        } catch (PaymentException $e) {
            return response()->json(['status' => 'failed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => $purchase->status, 'message' => $this->said($purchase)]);
    }

    /** The player closed PayPal's window without paying. */
    public function cancel(Request $request, string $order): JsonResponse
    {
        $purchase = $this->purchase($request, $order);

        if ($purchase?->status === 'pending') {
            $purchase->forceFill(['status' => 'failed'])->save();
        }

        return response()->json(['message' => 'You closed PayPal without paying. Nothing was charged.']);
    }

    /** Back from PayPal, having approved the order: take the money. */
    public function paypal(Request $request, Checkout $checkout): RedirectResponse
    {
        $purchase = $this->purchase($request, (string) $request->query('token', ''));

        if ($purchase === null) {
            return $this->toApples("We couldn't find that payment.", 'error');
        }

        try {
            $purchase = $checkout->settlePayPal($purchase);
        } catch (PaymentException $e) {
            return $this->toApples($e->getMessage(), 'error');
        }

        return $this->settled($purchase);
    }

    /** Back from PayPal without paying. */
    public function paypalCancel(Request $request): RedirectResponse
    {
        $purchase = $this->purchase($request, (string) $request->query('token', ''));

        if ($purchase?->status === 'pending') {
            $purchase->forceFill(['status' => 'failed'])->save();
        }

        return $this->toApples('You left PayPal without paying. Nothing was charged.');
    }

    /** Ask again about a payment that hadn't cleared. */
    public function check(Request $request, ApplePurchase $purchase, Checkout $checkout): RedirectResponse
    {
        abort_unless($purchase->player_id === $request->user()->player->id && $purchase->provider === 'paypal', 404);

        try {
            $purchase = $checkout->settlePayPal($purchase);
        } catch (PaymentException $e) {
            return $this->toApples($e->getMessage(), 'error');
        }

        return $this->settled($purchase);
    }

    /** Horseshoes for Apples, to skip the wait. */
    public function horseshoes(Request $request): RedirectResponse
    {
        $count = (int) $request->validate(['count' => ['required', 'integer']])['count'];
        $now = Horseshoes::buy($request->user()->player, $count);

        return $this->told("You bought {$count} horseshoes. You have {$now} now.");
    }

    /** Pineapples for a pack of Apples. */
    public function exchange(Request $request): RedirectResponse
    {
        $pack = Pineapples::swap($request->user()->player, (string) $request->validate(['pack' => ['required', 'string', 'max:32']])['pack']);

        return $this->told('You swapped '.number_format($pack['pineapples']).' Pineapples for '.number_format($pack['apples']).' Apples.');
    }

    public function swap(Request $request): RedirectResponse
    {
        $apples = (int) $request->validate(['apples' => ['required', 'integer', 'min:1', 'max:1000000']])['apples'];
        $corn = Apples::swapForCorn($request->user()->player, $apples);

        return $this->told('You swapped '.number_format($apples).' '.($apples === 1 ? 'Apple' : 'Apples').' for '.number_format($corn).' Corn.');
    }

    /** The player's purchase PayPal's order id points to. */
    private function purchase(Request $request, string $orderId): ?ApplePurchase
    {
        if ($orderId === '') {
            return null;
        }

        return ApplePurchase::query()
            ->where('provider', 'paypal')
            ->where('provider_id', $orderId)
            ->where('player_id', $request->user()->player->id)
            ->first();
    }

    private function settled(ApplePurchase $purchase): RedirectResponse
    {
        return $this->toApples($this->said($purchase), in_array($purchase->status, ['failed', 'review'], true) ? 'error' : 'success');
    }

    /** How a payment stands, in a sentence. */
    private function said(ApplePurchase $purchase): string
    {
        return match ($purchase->status) {
            'paid' => 'Your '.number_format($purchase->pineapples).' Pineapples arrived. Swap them for Apples below.',
            'failed' => "The payment didn't go through, so nothing was charged.",
            'review' => "The payment didn't match the pack, so we're checking it by hand. Your Pineapples arrive once we have.",
            default => 'PayPal is still confirming the payment. Your Pineapples arrive as soon as it does.',
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
