<?php

namespace Webkul\Payment\Listeners;

use RuntimeException;
use Webkul\Payment\Payment\Paymongo;

class CancelPaymongoCheckout
{
    /**
     * Create the cancellation listener.
     */
    public function __construct(protected Paymongo $gateway) {}

    /**
     * Prevent an administrator from canceling an order with a payable session.
     */
    public function handle($order): void
    {
        if ($order->payment?->method !== 'paymongo_gcash') {
            return;
        }

        $sessionId = $order->payment->additional['checkout_session_id'] ?? null;

        if (! $sessionId) {
            return;
        }

        $session = $this->gateway->retrieveCheckout($sessionId);

        if ($this->gateway->verifiedPayment($session, $order)) {
            throw new RuntimeException('A paid GCash order must be invoiced and refunded instead of canceled.');
        }

        if (data_get($session, 'attributes.status') !== 'expired') {
            $this->gateway->expireCheckout($sessionId);
        }
    }
}
