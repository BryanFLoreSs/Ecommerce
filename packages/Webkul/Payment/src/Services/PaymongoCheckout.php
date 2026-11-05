<?php

namespace Webkul\Payment\Services;

use RuntimeException;
use Webkul\Payment\Payment\Paymongo;
use Webkul\Payment\Repositories\PaymongoOrderRepository;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderTransactionRepository;

class PaymongoCheckout
{
    /**
     * Create the checkout reconciliation service.
     */
    public function __construct(
        protected Paymongo $gateway,
        protected PaymongoOrderRepository $orders,
        protected InvoiceRepository $invoices,
        protected OrderTransactionRepository $transactions
    ) {}

    /**
     * Retrieve authoritative payment details before fulfilling an order once.
     */
    public function complete(int $orderId): bool
    {
        return $this->orders->withLockedOrder($orderId, function ($order) {
            $sessionId = $order->payment->additional['checkout_session_id'] ?? null;

            if (! $sessionId) {
                return false;
            }

            $checkout = $this->gateway->retrieveCheckout($sessionId);
            $payment = $this->gateway->verifiedPayment($checkout, $order);

            if (! $payment) {
                return false;
            }

            if ($this->transactions->findOneWhere(['transaction_id' => $payment['id'], 'order_id' => $order->id])) {
                return true;
            }

            if (! $order->canInvoice()) {
                throw new RuntimeException('Paid GCash order requires manual reconciliation: '.$order->id);
            }

            $invoice = $this->invoices->create([
                'order_id' => $order->id,
                'invoice' => ['items' => $order->items->mapWithKeys(fn ($item) => [$item->id => $item->qty_to_invoice])->all()],
            ]);

            $this->transactions->create([
                'transaction_id' => $payment['id'],
                'status' => 'success',
                'type' => 'paymongo_gcash',
                'payment_method' => 'paymongo_gcash',
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'amount' => $order->base_grand_total,
                'data' => json_encode(['checkout_session_id' => $sessionId, 'payment_id' => $payment['id']]),
            ]);

            return true;
        });
    }

    /**
     * Release an unpaid order only after PayMongo has expired its checkout.
     */
    public function cancel(int $orderId): bool
    {
        return $this->orders->withLockedOrder($orderId, function ($order) {
            if ($order->status === 'canceled') {
                return true;
            }

            if ($order->status !== 'pending_payment') {
                return false;
            }

            $sessionId = $order->payment->additional['checkout_session_id'] ?? null;

            if ($sessionId) {
                $checkout = $this->gateway->retrieveCheckout($sessionId);

                if ($this->gateway->verifiedPayment($checkout, $order)) {
                    return false;
                }

                if (data_get($checkout, 'attributes.status') !== 'expired') {
                    $this->gateway->expireCheckout($sessionId);
                }
            }

            return $this->orders->cancel($order);
        });
    }
}
