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

    public function test_verify_reads_the_transaction_and_a_reusable_card(): void
    {
        Http::fake(['api.paystack.co/transaction/verify/mba_test_1' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'mba_test_1', 'status' => 'success', 'amount' => 25000, 'currency' => 'ZAR',
                'gateway_response' => 'Approved',
                'authorization' => ['authorization_code' => 'AUTH_x1', 'reusable' => true, 'last4' => '4081', 'card_type' => 'visa ', 'exp_month' => '12', 'exp_year' => '2030'],
                'customer' => ['email' => 'accounts@agent.example'],
            ],
        ])]);

        $result = $this->gateway()->verify('mba_test_1');

        $this->assertTrue($result->succeeded());
        $this->assertSame(25000, $result->amountInCents);
        $this->assertSame('AUTH_x1', $result->card->authorizationCode);
        $this->assertSame('4081', $result->card->last4);
        $this->assertSame('12/2030', $result->card->expiry);
        $this->assertSame('accounts@agent.example', $result->card->email);
    }

    public function test_a_saved_card_is_charged_with_its_authorization(): void
    {
        Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response([
            'status' => true,
            'data' => ['reference' => 'mba_test_1', 'status' => 'failed', 'amount' => 25000, 'currency' => 'ZAR', 'gateway_response' => 'Insufficient Funds'],
        ])]);

        $result = $this->gateway()->chargeSavedCard($this->payment(), 'AUTH_x1', 'accounts@agent.example');

        $this->assertFalse($result->succeeded());
        $this->assertSame('Insufficient Funds', $result->message);
        Http::assertSent(fn (Request $request) => $request['authorization_code'] === 'AUTH_x1'
            && $request['email'] === 'accounts@agent.example'
            && $request['amount'] === 25000
            && $request['reference'] === 'mba_test_1');
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
