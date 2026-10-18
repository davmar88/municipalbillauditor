<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cashout;
use App\Payments\Cashouts;
use App\Payments\PayPal;
use App\Payments\Paystack;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's cash-out queue. Every cash-out waits here until the owner
 * pays it through Paystack or PayPal, or turns it down and the Apples go
 * back.
 */
class CashoutController extends Controller
{
    public function index(Cashouts $cashouts, Paystack $paystack, PayPal $paypal, Settings $settings): Response
    {
        $queue = Cashout::query()
            ->with(['player:id,name,created_at', 'player.payoutAccount:id,player_id,updated_at'])
            ->whereIn('status', ['pending', 'sending'])
            ->oldest('id')
            ->limit(100)
            ->get();

        $players = $queue->pluck('player_id')->filter()->unique()->values()->all();
        $sources = Cashouts::sources($players);
        $paidBefore = Cashout::query()->whereIn('player_id', $players)->where('status', 'paid')->groupBy('player_id')->selectRaw('player_id, COUNT(*) AS count')->pluck('count', 'player_id');
        $paid = Cashout::query()->where('status', 'paid');

        return Inertia::render('admin/cashouts', [
            'open' => $cashouts->open(),
            'ready' => ['paystack' => $paystack->ready(), 'paypal' => $paypal->ready()],
            'live' => [
                'paystack' => str_starts_with((string) $settings->get('paystack_secret_key'), 'sk_live_'),
                'paypal' => $paypal->live(),
            ],
            'totals' => [
                'count' => (clone $paid)->count(),
                'apples' => (int) (clone $paid)->sum('apples'),
                'zar' => (int) (clone $paid)->where('currency', 'ZAR')->sum('amount'),
                'usd' => (int) (clone $paid)->where('currency', 'USD')->sum('amount'),
            ],
            'queue' => $queue->map(fn (Cashout $cashout) => [
                ...$this->card($cashout),
                'joined' => $cashout->player?->created_at?->toIso8601String(),
                'detailsChanged' => $cashout->player?->payoutAccount?->updated_at?->toIso8601String(),
                'sources' => $sources[$cashout->player_id] ?? null,
                'paidBefore' => (int) ($paidBefore[$cashout->player_id] ?? 0),
                'otp' => $cashout->note === Cashouts::OTP_NOTE,
            ]),
            'history' => Cashout::query()
                ->with(['player:id,name', 'reviewer:id,name'])
                ->whereIn('status', ['paid', 'failed', 'rejected'])
                ->latest('updated_at')
                ->limit(30)
                ->get()
                ->map(fn (Cashout $cashout) => [...$this->card($cashout), 'by' => $cashout->reviewer?->name]),
        ]);
    }

    public function pay(Request $request, Cashout $cashout, Cashouts $cashouts): RedirectResponse
    {
        return $this->outcome($cashouts->pay($cashout, $request->user()));
    }

    public function check(Cashout $cashout, Cashouts $cashouts): RedirectResponse
    {
        return $this->outcome($cashouts->check($cashout));
    }

    public function otp(Request $request, Cashout $cashout, Cashouts $cashouts): RedirectResponse
    {
        $otp = $request->validate(['otp' => ['required', 'string', 'regex:/^\d{4,8}$/']], ['otp.regex' => 'The OTP is the 4 to 8 digits Paystack texted you.'])['otp'];

        return $this->outcome($cashouts->otp($cashout, $otp));
    }

    public function resendOtp(Cashout $cashout, Cashouts $cashouts): RedirectResponse
    {
        $cashouts->resendOtp($cashout);

        return $this->told('Paystack is texting you a new OTP.');
    }

    public function reject(Request $request, Cashout $cashout, Cashouts $cashouts): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']], ['reason.required' => 'Say why, so the animal knows.'])['reason'];
        $cashouts->reject($cashout, $request->user(), $reason);

        return $this->told('Turned down. The '.number_format($cashout->apples).' Apples went back, and we told the animal why.');
    }

    /** @return array<string, mixed> */
    private function card(Cashout $cashout): array
    {
        return [
            'id' => $cashout->id,
            'animal' => $cashout->player?->name ?? 'A deleted account',
            'apples' => $cashout->apples,
            'price' => $cashout->price(),
            'method' => $cashout->method,
            'name' => $cashout->destination['name'] ?? '',
            'document' => $cashout->destination['document'] ?? '',
            'label' => $cashout->destination['label'] ?? '',
            // The owner needs it in full to look a payout up in PayPal.
            'email' => $cashout->destination['email'] ?? null,
            'reference' => $cashout->reference,
            'transaction' => $cashout->provider_id,
            'status' => $cashout->status,
            'note' => $cashout->note,
            'at' => $cashout->created_at->toIso8601String(),
        ];
    }

    /** Back to the queue, saying where the cash-out now stands. */
    private function outcome(Cashout $cashout): RedirectResponse
    {
        return match ($cashout->status) {
            'paid' => $this->told("Paid {$cashout->price()}. We told the animal."),
            'failed' => $this->told("It didn't go through, so the Apples went back to the animal. {$cashout->note}", 'error'),
            'pending' => $this->told("Nothing was sent. {$cashout->note}", 'error'),
            default => $this->told($cashout->note ?? 'On its way.'),
        };
    }
}
