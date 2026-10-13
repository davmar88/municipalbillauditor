<?php

namespace App\Http\Controllers;

use App\Services\Payments\ChargeResult;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paystack's webhook. The signature is checked against the raw body before
 * anything is read from it. Only charge.success does anything; every other
 * event is acknowledged and ignored, so Paystack doesn't retry it.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, PaymentService $payments): JsonResponse
    {
        if (! $gateway->validSignature($request->getContent(), $request->header('x-paystack-signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = json_decode($request->getContent(), true);

        if (is_array($event) && ($event['event'] ?? null) === 'charge.success' && is_array($event['data'] ?? null)) {
            $payments->confirm(ChargeResult::fromPaystack($event['data']));
        }

        return response()->json(['received' => true]);
    }
}
