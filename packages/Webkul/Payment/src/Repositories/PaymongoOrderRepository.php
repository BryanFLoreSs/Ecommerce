<?php

namespace Webkul\Payment\Repositories;

use Closure;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Repositories\OrderRepository;

class PaymongoOrderRepository extends OrderRepository
{
    /**
     * Serialize payment notifications and cancellations for one order.
     */
    public function withLockedOrder(int $id, Closure $callback): mixed
    {
        return DB::transaction(function () use ($id, $callback) {
            $order = $this->model->newQuery()->lockForUpdate()->findOrFail($id);

            abort_unless($order->payment?->method === 'paymongo_gcash', 404);

            return $callback($order);
        });
    }

    /**
     * Persist the gateway session against the order's payment.
     */
    public function saveCheckout(int $id, array $checkout): void
    {
        $this->findOrFail($id)->payment->update(['additional' => [
            'checkout_session_id' => $checkout['id'],
        ]]);
    }
}
