<?php

namespace Tests\Unit\Payments;

use App\Models\Payment;
use App\Services\Payments\PaymentFailed;
use App\Services\Payments\PaystackGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The requests we send Paystack and how we read its answers. No network:
 * Http::fake stands in for the API.
 */
class PaystackGatewayTest extends TestCase
{
    private function gateway(): PaystackGateway
    {
        return new PaystackGateway('sk_test_secret');
    }

    private function payment(): Payment
    {
        $payment = new Payment(['organisation_id' => 7, 'amount' => '250.00', 'currency' => 'ZAR', 'reference' => 'mba_test_1']);
        $payment->id = 42;

        return $payment;
    }

    public function test_checkout_sends_the_amount_in_cents_and_our_reference(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => 'mba_test_1'],
        ])]);

        $url = $this->gateway()->checkout($this->payment(), 'thandi@example.com', 'https://app.test/credit/callback');

        $this->assertSame('https://checkout.paystack.com/abc', $url);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request->hasHeader('Authorization', 'Bearer sk_test_secret')
            && $request['amount'] === 25000
            && $request['currency'] === 'ZAR'
            && $request['email'] === 'thandi@example.com'
            && $request['reference'] === 'mba_test_1'
            && $request['callback_url'] === 'https://app.test/credit/callback'
            && $request['metadata'] === ['organisation_id' => 7, 'payment_id' => 42]);
    }

    public function test_verify_reads_the_transaction(): void
    {
        Http::fake(['api.paystack.co/transaction/verify/mba_test_1' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'mba_test_1', 'status' => 'success', 'amount' => 25000, 'currency' => 'ZAR',
                'gateway_response' => 'Approved',
            ],
        ])]);

        $result = $this->gateway()->verify('mba_test_1');

        $this->assertTrue($result->succeeded());
        $this->assertSame(25000, $result->amountInCents);
        $this->assertSame('ZAR', $result->currency);
        $this->assertSame('Approved', $result->message);
    }

    public function test_payments_stay_in_the_main_account_and_name_the_site(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']]),
        ]);
        $gateway = new PaystackGateway('sk_test_secret', site: 'revenueguard.co.za');

        $gateway->checkout($this->payment(), 'thandi@example.com', 'https://app.test/cb');

        $sent = Http::recorded();
        $this->assertCount(1, $sent);

        foreach ($sent as [$request]) {
            // Nothing that would send part of a payment to a subaccount.
            $this->assertSame([], array_intersect_key($request->data(), array_flip(['subaccount', 'split_code', 'split', 'bearer', 'transaction_charge'])));
            $this->assertSame([['display_name' => 'Site', 'variable_name' => 'site', 'value' => 'revenueguard.co.za']], $request['metadata']['custom_fields']);
        }
    }

    public function test_a_refused_request_is_a_safe_failure(): void
    {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

        $this->expectException(PaymentFailed::class);
        $this->expectExceptionMessage('Invalid key');

        $this->gateway()->checkout($this->payment(), 'thandi@example.com', 'https://app.test/cb');
    }

    public function test_webhook_signatures_are_an_hmac_of_the_raw_body(): void
    {
        $body = '{"event":"charge.success","data":{"reference":"mba_test_1"}}';

        $this->assertTrue($this->gateway()->validSignature($body, hash_hmac('sha512', $body, 'sk_test_secret')));
        $this->assertFalse($this->gateway()->validSignature($body.' ', hash_hmac('sha512', $body, 'sk_test_secret')));
        $this->assertFalse($this->gateway()->validSignature($body, null));
        $this->assertFalse((new PaystackGateway(''))->validSignature($body, hash_hmac('sha512', $body, '')));
    }
}
