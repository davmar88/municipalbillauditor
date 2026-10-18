<?php

namespace App\Http\Controllers;

use App\Models\ApplePurchase;
use App\Payments\Cashouts;
use App\Payments\Checkout;
use App\Payments\Paystack;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Paystack tells us here about every payment, signed with our secret key,
 * so Apples arrive even when the player closes the page before coming back.
 * It tells us here too when a cash-out's transfer arrives, fails or comes
 * back. Paystack sends again until it gets a 200.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, Paystack $paystack, Checkout $checkout, Cashouts $cashouts): Response
    {
        if (! $paystack->signed($request->getContent(), $request->header('x-paystack-signature'))) {
            return response('', 401);
        }

        $event = $request->json('event');
        $data = $request->json('data');

        if (! is_array($data) || ! is_string($data['reference'] ?? null)) {
            return response('', 200);
        }

        if ($event === 'charge.success') {
            $purchase = ApplePurchase::query()->where('provider', 'paystack')->where('reference', $data['reference'])->first();

            if ($purchase !== null) {
                $checkout->settlePaystack($purchase, $data);
            }
        }

        if (in_array($event, ['transfer.success', 'transfer.failed', 'transfer.reversed'], true)) {
            $cashouts->settlePaystackTransfer($event, $data);
        }

        return response('', 200);
    }
}
