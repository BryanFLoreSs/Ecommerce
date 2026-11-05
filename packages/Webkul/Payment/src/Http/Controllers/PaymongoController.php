<?php

namespace Webkul\Payment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Payment\Payment\Paymongo;
use Webkul\Payment\Repositories\PaymongoOrderRepository;
use Webkul\Payment\Services\PaymongoCheckout;
use Webkul\Sales\Transformers\OrderResource;
use Webkul\Shop\Http\Controllers\API\OnepageController;

class PaymongoController extends Controller
{
    /**
     * Create the payment controller.
     */
    public function __construct(
        protected Paymongo $gateway,
        protected PaymongoOrderRepository $orders,
        protected PaymongoCheckout $checkout,
        protected CartRepository $carts
    ) {}

    /**
     * Reserve the order before sending the shopper to hosted GCash checkout.
     */
    public function redirect(): RedirectResponse
    {
        $cart = Cart::getCart();

        if (
            ! $cart
            || $cart->payment?->method !== 'paymongo_gcash'
            || ! $this->gateway->isAvailable()
            || Cart::requiresSignInToCheckout()
        ) {
            return $this->failure();
        }

        try {
            return Cache::lock('paymongo-cart-'.$cart->id, 90)->block(5, function () use ($cart) {
                abort_unless($this->carts->findOrFail($cart->id)->is_active, 409);

                Cart::collectTotals();

                if (Cart::hasError()) {
                    return $this->failure();
                }

                app(OnepageController::class)->validateOrder();

                $data = (new OrderResource(Cart::getCart()))->jsonSerialize();
                $data['payment']['additional'] = [];
                $order = $this->orders->create($data);
                $this->orders->update(['status' => 'pending_payment'], $order->id);

                try {
                    $session = $this->gateway->createCheckout($order);
                    $url = data_get($session, 'attributes.checkout_url');

                    if (
                        ! preg_match('/^cs_[a-zA-Z0-9]+$/', $session['id'] ?? '')
                        || ! is_string($url)
                        || parse_url($url, PHP_URL_SCHEME) !== 'https'
                        || parse_url($url, PHP_URL_HOST) !== 'checkout.paymongo.com'
                    ) {
                        throw new \RuntimeException('Invalid PayMongo checkout response.');
                    }

                    $this->orders->saveCheckout($order->id, $session);
                } catch (Throwable $e) {
                    report($e);

                    $this->orders->cancel($order);

                    return $this->failure();
                }

                session()->put('paymongo.orders.'.$order->id, $cart->id);

                Cart::deActivateCart();

                return redirect()->away($url);
            });
        } catch (Throwable $e) {
            report($e);

            return $this->failure();
        }
    }

    /**
     * Verify payment after the signed browser return.
     */
    public function success(int $orderId): RedirectResponse
    {
        try {
            if ($this->checkout->complete($orderId)) {
                session()->flash('order_id', $orderId);

                return redirect()->route('shop.checkout.onepage.success');
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $this->failure('payment::app.pending');
    }

    /**
     * Cancel unpaid checkout and restore the initiating shopper's cart.
     */
    public function cancel(int $orderId): RedirectResponse
    {
        try {
            if ($this->checkout->complete($orderId)) {
                session()->flash('order_id', $orderId);

                return redirect()->route('shop.checkout.onepage.success');
            }

            if (! $this->checkout->cancel($orderId)) {
                return $this->failure('payment::app.pending');
            }

            if ($cartId = session()->pull('paymongo.orders.'.$orderId)) {
                $cart = $this->carts->update(['is_active' => true], $cartId);

                Cart::setCart($cart);
            }

            return $this->failure('payment::app.canceled');
        } catch (Throwable $e) {
            report($e);

            return $this->failure('payment::app.pending');
        }
    }

    /**
     * Reconcile signed notifications even when the shopper closes their browser.
     */
    public function webhook(Request $request): JsonResponse
    {
        abort_unless($this->gateway->validSignature($request->getContent(), $request->header('Paymongo-Signature', '')), 403);

        if ($request->input('data.attributes.type') !== 'checkout_session.payment.paid') {
            return response()->json(['status' => 'ignored']);
        }

        $session = $request->input('data.attributes.data', []);
        $orderId = data_get($session, 'attributes.reference_number');

        abort_unless(ctype_digit((string) $orderId), 422);

        $order = $this->orders->findOrFail((int) $orderId);

        abort_unless(($order->payment->additional['checkout_session_id'] ?? null) === ($session['id'] ?? null), 422);

        if (! $this->checkout->complete((int) $orderId)) {
            return response()->json(['status' => 'unconfirmed'], 503);
        }

        return response()->json(['status' => 'paid']);
    }

    /**
     * Return a localized checkout message without exposing gateway responses.
     */
    protected function failure(string $message = 'payment::app.failed'): RedirectResponse
    {
        session()->flash('error', trans($message));

        return redirect()->route('shop.checkout.cart.index');
    }
}
