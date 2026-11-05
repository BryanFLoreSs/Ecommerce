<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Webkul\Checkout\Facades\Cart;
use Webkul\Core\Models\Currency;
use Webkul\Payment\Payment\Paymongo;
use Webkul\Payment\Repositories\PaymongoOrderRepository;
use Webkul\Payment\Services\PaymongoCheckout;
use Webkul\Payment\Tests\Concerns\ProvidePaymentHelpers;
use Webkul\Sales\Models\Invoice;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderTransaction;
use Webkul\Sales\Transformers\OrderResource;

uses(ProvidePaymentHelpers::class);

beforeEach(function () {
    Http::preventStrayRequests();

    $this->setConfig([
        'sales.payment_methods.paymongo_gcash.active' => '1',
        'sales.payment_methods.paymongo_gcash.secret_key' => 'sk_test_example',
        'sales.payment_methods.paymongo_gcash.webhook_secret' => 'whsec_example',
    ]);

    $cart = $this->createCartWithItems('paymongo_gcash');
    $data = (new OrderResource($cart))->jsonSerialize();
    $data['order_currency_code'] = 'PHP';
    $data['payment']['additional'] = [];
    $this->order = app(PaymongoOrderRepository::class)->create($data);
    app(PaymongoOrderRepository::class)->update(['status' => 'pending_payment'], $this->order->id);
    app(PaymongoOrderRepository::class)->saveCheckout($this->order->id, ['id' => 'cs_example']);
    $this->order->refresh();

    $this->paidSession = [
        'id' => 'cs_example',
        'attributes' => [
            'reference_number' => (string) $this->order->id,
            'livemode' => false,
            'status' => 'active',
            'payments' => [[
                'id' => 'pay_example',
                'attributes' => [
                    'status' => 'paid',
                    'amount' => (int) round($this->order->grand_total * 100),
                    'currency' => 'PHP',
                    'source' => ['type' => 'gcash'],
                ],
            ]],
        ],
    ];
});

// ============================================================================
// Checkout Creation
// ============================================================================

it('registers GCash settings in the admin payment configuration', function () {
    expect(core()->getConfigField('sales.payment_methods.paymongo_gcash.secret_key')['type'])->toBe('password')
        ->and(config('payment_methods.paymongo_gcash.class'))->toBe(Paymongo::class)
        ->and(trans('payment::app.gcash'))->toBe('GCash (PayMongo)');
});

it('sends only GCash and the server calculated order amount to PayMongo', function () {
    Http::fake(['api.paymongo.com/v1/checkout_sessions' => Http::response([
        'data' => ['id' => 'cs_new', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_new']],
    ])]);

    app(Paymongo::class)->createCheckout($this->order);

    Http::assertSent(fn ($request) => $request['data']['attributes']['payment_method_types'] === ['gcash']
        && $request['data']['attributes']['line_items'][0]['amount'] === (int) round($this->order->grand_total * 100)
        && $request['data']['attributes']['line_items'][0]['currency'] === 'PHP'
        && str_contains($request['data']['attributes']['success_url'], 'signature='));
});

it('reserves a peso order and redirects the customer to hosted GCash checkout', function (bool $gatewayFails) {
    $currency = Currency::firstOrCreate(['code' => 'PHP'], ['name' => 'Philippine Peso', 'symbol' => '₱', 'decimal' => 2]);
    $channel = core()->getCurrentChannel();
    $channel->currencies()->syncWithoutDetaching([$currency->id]);
    $channel->unsetRelation('currencies');
    core()->setCurrentCurrency('PHP');

    $cart = $this->createCartWithItems('paymongo_gcash');
    $this->actingAs($cart->customer, 'customer')->withSession(['currency' => 'PHP']);

    Http::fake(['api.paymongo.com/v1/checkout_sessions' => $gatewayFails
        ? Http::response(['errors' => [['detail' => 'Test refusal']]], 400)
        : Http::response(['data' => ['id' => 'cs_new', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_new']]])]);

    $response = $this->get(route('paymongo.redirect'));
    $order = Order::where('cart_id', $cart->id)->first();

    if ($gatewayFails) {
        $response->assertRedirect(route('shop.checkout.cart.index'));
        expect($order?->status)->toBe('canceled')
            ->and((bool) $cart->fresh()->is_active)->toBeTrue();
    } else {
        $response->assertRedirect('https://checkout.paymongo.com/cs_new');
        expect($order?->status)->toBe('pending_payment')
            ->and($order?->order_currency_code)->toBe('PHP')
            ->and((bool) $cart->fresh()->is_active)->toBeFalse();
    }

    Cart::resetCart();
})->with([false, true]);

// ============================================================================
// Reconciliation
// ============================================================================

it('creates one paid invoice and transaction when callback and webhook repeat', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => $this->paidSession])]);

    expect(app(PaymongoCheckout::class)->complete($this->order->id))->toBeTrue()
        ->and(app(PaymongoCheckout::class)->complete($this->order->id))->toBeTrue()
        ->and(Invoice::where('order_id', $this->order->id)->count())->toBe(1)
        ->and(OrderTransaction::where('order_id', $this->order->id)->count())->toBe(1);
});

it('does not invoice a mismatched or pending payment', function (string $path, mixed $value) {
    data_set($this->paidSession, $path, $value);
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => $this->paidSession])]);

    expect(app(PaymongoCheckout::class)->complete($this->order->id))->toBeFalse()
        ->and(Invoice::where('order_id', $this->order->id)->count())->toBe(0);
})->with([
    ['attributes.payments.0.attributes.status', 'pending'],
    ['attributes.payments.0.attributes.amount', 1],
    ['attributes.reference_number', '0'],
]);

it('requires signed browser returns', function () {
    $this->get(route('paymongo.success', ['orderId' => $this->order->id]))->assertForbidden();
    $this->get(route('paymongo.cancel', ['orderId' => $this->order->id]))->assertForbidden();

    Http::assertNothingSent();
});

it('shows success only after checking the payment with PayMongo', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => $this->paidSession])]);

    $this->get(URL::signedRoute('paymongo.success', ['orderId' => $this->order->id]))
        ->assertRedirect(route('shop.checkout.onepage.success'))
        ->assertSessionHas('order_id', $this->order->id);
});

// ============================================================================
// Cancellation And Webhooks
// ============================================================================

it('expires an unpaid checkout before canceling the reserved order', function () {
    $this->paidSession['attributes']['payments'] = [];
    Http::fake([
        'api.paymongo.com/v1/checkout_sessions/cs_example/expire' => Http::response(['data' => []]),
        'api.paymongo.com/v1/checkout_sessions/cs_example' => Http::response(['data' => $this->paidSession]),
    ]);

    expect(app(PaymongoCheckout::class)->cancel($this->order->id))->toBeTrue()
        ->and($this->order->fresh()->status)->toBe('canceled');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/expire'));
});

it('keeps the order reserved if PayMongo refuses expiration', function () {
    $this->paidSession['attributes']['payments'] = [];
    Http::fake([
        'api.paymongo.com/v1/checkout_sessions/cs_example/expire' => Http::response([], 400),
        'api.paymongo.com/v1/checkout_sessions/cs_example' => Http::response(['data' => $this->paidSession]),
    ]);

    expect(fn () => app(PaymongoCheckout::class)->cancel($this->order->id))->toThrow(RequestException::class)
        ->and($this->order->fresh()->status)->toBe('pending_payment');
});

it('prevents administrative cancellation of a paid GCash checkout', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => $this->paidSession])]);

    expect(fn () => app(PaymongoOrderRepository::class)->cancel($this->order))->toThrow(RuntimeException::class)
        ->and($this->order->fresh()->status)->toBe('pending_payment');
});

it('rejects an unsigned webhook before contacting the gateway', function () {
    $this->postJson(route('paymongo.webhook'), ['data' => []])->assertForbidden();

    Http::assertNothingSent();
});

it('reconciles a signed webhook without a browser return', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => $this->paidSession])]);
    $body = json_encode(['data' => ['attributes' => [
        'type' => 'checkout_session.payment.paid',
        'data' => $this->paidSession,
    ]]]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_example');

    $this->call('POST', route('paymongo.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PAYMONGO_SIGNATURE' => 't='.$timestamp.',te='.$signature,
    ], $body)->assertOk()->assertJson(['status' => 'paid']);

    expect(Invoice::where('order_id', $this->order->id)->count())->toBe(1);
});
