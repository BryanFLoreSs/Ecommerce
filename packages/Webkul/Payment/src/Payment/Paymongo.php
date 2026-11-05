<?php

namespace Webkul\Payment\Payment;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class Paymongo extends Payment
{
    /**
     * Payment method code.
     */
    protected $code = 'paymongo_gcash';

    /**
     * Return the gateway entry point.
     */
    public function getRedirectUrl(): string
    {
        return route('paymongo.redirect');
    }

    /**
     * Offer GCash only for configured peso checkouts.
     */
    public function isAvailable(): bool
    {
        return (bool) parent::isAvailable()
            && preg_match('/^sk_(test|live)_\S+$/', (string) $this->getConfigData('secret_key')) === 1
            && ! $this->getCart()?->is_buy_now
            && strtoupper((string) $this->getCart()?->cart_currency_code) === 'PHP';
    }

    /**
     * Return the GCash payment badge.
     */
    public function getImage(): string
    {
        return asset('images/gcash.svg');
    }

    /**
     * Create a hosted checkout for an immutable order total.
     */
    public function createCheckout(object $order): array
    {
        if (strtoupper($order->order_currency_code) !== 'PHP') {
            throw new RuntimeException('GCash requires PHP currency.');
        }

        return $this->client()->post('https://api.paymongo.com/v1/checkout_sessions', [
            'data' => ['attributes' => [
                'line_items' => [[
                    'name' => 'Order '.$order->increment_id,
                    'amount' => (int) round($order->grand_total * 100),
                    'currency' => 'PHP',
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['gcash'],
                'reference_number' => (string) $order->id,
                'success_url' => URL::signedRoute('paymongo.success', ['orderId' => $order->id]),
                'cancel_url' => URL::signedRoute('paymongo.cancel', ['orderId' => $order->id]),
                'send_email_receipt' => false,
                'show_line_items' => true,
                'description' => 'Order '.$order->increment_id,
            ]],
        ])->throw()->json('data') ?? [];
    }

    /**
     * Retrieve payment status using the server's secret key.
     */
    public function retrieveCheckout(string $id): array
    {
        if (! preg_match('/^cs_[a-zA-Z0-9]+$/', $id)) {
            throw new RuntimeException('Invalid checkout session.');
        }

        return $this->client()->get('https://api.paymongo.com/v1/checkout_sessions/'.$id)
            ->throw()->json('data') ?? [];
    }

    /**
     * Expire a checkout before releasing its reserved inventory.
     */
    public function expireCheckout(string $id): void
    {
        $this->client()->post('https://api.paymongo.com/v1/checkout_sessions/'.rawurlencode($id).'/expire')->throw();
    }

    /**
     * Require a paid GCash transaction matching the saved order and session.
     */
    public function verifiedPayment(array $checkout, object $order): ?array
    {
        if (
            ($checkout['id'] ?? null) !== ($order->payment->additional['checkout_session_id'] ?? null)
            || (string) data_get($checkout, 'attributes.reference_number') !== (string) $order->id
            || $order->payment->method !== $this->code
            || strtoupper($order->order_currency_code) !== 'PHP'
            || data_get($checkout, 'attributes.livemode') !== str_starts_with((string) $this->getConfigData('secret_key'), 'sk_live_')
        ) {
            return null;
        }

        foreach (data_get($checkout, 'attributes.payments', []) as $payment) {
            if (
                ! empty($payment['id'])
                && data_get($payment, 'attributes.status') === 'paid'
                && data_get($payment, 'attributes.currency') === 'PHP'
                && data_get($payment, 'attributes.source.type') === 'gcash'
                && (int) data_get($payment, 'attributes.amount') === (int) round($order->grand_total * 100)
            ) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * Authenticate the raw webhook body and reject stale notifications.
     */
    public function validSignature(string $body, string $header): bool
    {
        $secret = (string) $this->getConfigData('webhook_secret');
        $parts = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        $timestamp = $parts['t'] ?? '';
        $mode = str_starts_with((string) $this->getConfigData('secret_key'), 'sk_live_') ? 'li' : 'te';

        return $secret !== ''
            && ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= 300
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $parts[$mode] ?? '');
    }

    /**
     * Build authenticated requests without exposing credentials to the browser.
     */
    protected function client(): PendingRequest
    {
        return Http::withBasicAuth((string) $this->getConfigData('secret_key'), '')
            ->acceptJson()->asJson()->connectTimeout(10)->timeout(25);
    }
}
