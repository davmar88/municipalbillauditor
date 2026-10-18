<?php

namespace App\Http\Controllers\Game;

use App\Game\GameException;
use App\Http\Controllers\Controller;
use App\Models\Cashout;
use App\Models\Player;
use App\Payments\Cashouts;
use App\Payments\Paystack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Cashing Apples out: where the money goes and who to, and asking for it. */
class CashoutController extends Controller
{
    /** The Cash out page: payout details, asking for a cash-out, and past ones. */
    public function index(Request $request, Cashouts $cashouts, Paystack $paystack): Response
    {
        return Inertia::render('game/cashout', [
            'cashout' => $this->cashout($request->user()->player, $cashouts, $paystack),
        ]);
    }

    public function account(Request $request, Cashouts $cashouts): RedirectResponse
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'min:3', 'max:100', "regex:/^[\\pL\\pM' .-]+$/u"],
            'country' => ['required', Rule::in(['ZA', 'ZZ'])],
            'document_type' => ['required', Rule::in(['id', 'passport'])],
            'document_number' => ['required', 'string', 'max:30'],
            'method' => ['required', Rule::in(Cashouts::METHODS)],
            'paypal_email' => ['nullable', 'required_if:method,paypal', 'email', 'max:200'],
            'bank_code' => ['nullable', 'required_if:method,paystack', 'string', 'max:16'],
            'account_number' => ['nullable', 'required_if:method,paystack', 'regex:/^\d{6,16}$/'],
            'mine' => ['accepted'],
        ], [
            'legal_name.regex' => 'Write your name as it is on your ID, in letters.',
            'paypal_email.required_if' => 'Add the email address of your PayPal account.',
            'bank_code.required_if' => 'Pick your bank.',
            'account_number.required_if' => 'Add your account number.',
            'account_number.regex' => 'An account number is 6 to 16 digits.',
            'mine.accepted' => 'Confirm these are your own details.',
        ]);

        // Shown on the form, which stays open with what was typed.
        try {
            $account = $cashouts->saveAccount($request->user()->player, $data);
        } catch (GameException $e) {
            throw ValidationException::withMessages(['details' => $e->getMessage()]);
        }

        return $this->told($account->method === 'paystack' ? 'Saved. The bank confirmed the account is yours.' : 'Saved.');
    }

    public function store(Request $request, Cashouts $cashouts): RedirectResponse
    {
        $apples = (int) $request->validate(['apples' => ['required', 'integer', 'min:1', 'max:1000000']])['apples'];
        $cashout = $cashouts->request($request->user()->player, $apples);

        return $this->told('You asked to cash out '.number_format($apples)." Apples for {$cashout->price()}. Our team checks every cash-out before it's sent, and we'll tell you when it's paid.");
    }

    /** @return array<string, mixed> What the Cash out part of the page needs. */
    private function cashout(Player $player, Cashouts $cashouts, Paystack $paystack): array
    {
        $ways = $cashouts->ways();
        $account = $player->payoutAccount;

        return [
            'open' => $cashouts->open(),
            'ways' => $ways,
            'saveable' => $cashouts->saveable(),
            'per100' => config('game.cashout.per_100'),
            'min' => Cashouts::min(),
            'dailyCap' => Cashouts::dailyCap(),
            'holdDays' => Cashouts::holdDays(),
            'held' => Cashouts::held($player),
            'available' => Cashouts::available($player),
            // Their own details, with the numbers hidden: changing them means typing them again.
            'account' => $account === null ? null : [
                'method' => $account->method,
                'label' => $account->describe(),
                'name' => $account->legal_name,
                'country' => $account->country,
                'documentType' => $account->document_type,
                'document' => $account->maskedDocument(),
                'bankCode' => $account->bank_code,
            ],
            'banks' => $ways['paystack'] ? $paystack->banks() : [],
            'history' => Cashout::query()
                ->where('player_id', $player->id)
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (Cashout $cashout) => [
                    'id' => $cashout->id,
                    'apples' => $cashout->apples,
                    'price' => $cashout->price(),
                    'label' => $cashout->destination['label'] ?? '',
                    'status' => $cashout->status,
                    // Only the owner's reason for turning one down is for the animal's eyes.
                    'reason' => $cashout->status === 'rejected' ? $cashout->note : null,
                    'at' => $cashout->created_at->toIso8601String(),
                ]),
        ];
    }
}
