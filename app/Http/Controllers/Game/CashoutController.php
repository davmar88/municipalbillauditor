<?php

namespace App\Http\Controllers\Game;

use App\Game\GameException;
use App\Http\Controllers\Controller;
use App\Payments\Cashouts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Cashing Apples out: where the money goes and who to, and asking for it. */
class CashoutController extends Controller
{
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
            'bitcoin_address' => ['nullable', 'required_if:method,bitcoin', 'string', 'max:100'],
            'mine' => ['accepted'],
        ], [
            'legal_name.regex' => 'Write your name as it is on your ID, in letters.',
            'paypal_email.required_if' => 'Add the email address of your PayPal account.',
            'bank_code.required_if' => 'Pick your bank.',
            'account_number.required_if' => 'Add your account number.',
            'account_number.regex' => 'An account number is 6 to 16 digits.',
            'bitcoin_address.required_if' => 'Add your Bitcoin address.',
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
}
