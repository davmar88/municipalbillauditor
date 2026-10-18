<?php

namespace App\Http\Controllers;

use App\Models\ApplePurchase;
use App\Payments\Checkout;
use App\Payments\Paystack;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Paystack tells us here about every payment, signed with our secret key,
 * so Apples arrive even when the player closes the page before coming back.
 * Paystack sends again until it gets a 200.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, Paystack $paystack, Checkout $checkout): Response
    {
        if (! $paystack->signed($request->getContent(), $request->header('x-paystack-signature'))) {
            return response('', 401);
        }

        $data = $request->json('data');

        if ($request->json('event') === 'charge.success' && is_array($data) && is_string($data['reference'] ?? null)) {
            $purchase = ApplePurchase::query()->where('provider', 'paystack')->where('reference', $data['reference'])->first();

            if ($purchase !== null) {
                $checkout->settlePaystack($purchase, $data);
            }
        }

        return response('', 200);
    }
}
