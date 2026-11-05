<?php

use Webkul\Payment\Payment\Paymongo;

/**
 * A gateway whose test credentials never come from the store configuration.
 */
function paymongoTestGateway(array $config = []): Paymongo
{
    $gateway = Mockery::mock(Paymongo::class)->makePartial();
    $gateway->shouldReceive('getConfigData')->andReturnUsing(fn ($field) => $config[$field] ?? null);

    return $gateway;
}

/**
 * An authoritative checkout response for a paid peso order.
 */
function paymongoPaidCheckout(): array
{
    return [
        'id' => 'cs_example',
        'attributes' => [
            'reference_number' => '42',
            'livemode' => false,
            'payments' => [[
                'id' => 'pay_example',
                'attributes' => [
                    'status' => 'paid',
                    'amount' => 12550,
                    'currency' => 'PHP',
                    'source' => ['type' => 'gcash'],
                ],
            ]],
        ],
    ];
}

/**
 * The order bound to the checkout response.
 */
function paymongoTestOrder(): object
{
    return (object) [
        'id' => 42,
        'grand_total' => 125.50,
        'order_currency_code' => 'PHP',
        'payment' => (object) [
            'method' => 'paymongo_gcash',
            'additional' => ['checkout_session_id' => 'cs_example'],
        ],
    ];
}

// ============================================================================
// Payment Verification
// ============================================================================

it('accepts only a verified GCash payment matching the order', function () {
    $gateway = paymongoTestGateway(['secret_key' => 'sk_test_example']);

    expect($gateway->verifiedPayment(paymongoPaidCheckout(), paymongoTestOrder())['id'])->toBe('pay_example');
});

it('rejects unpaid, unrelated, underpaid, wrong currency and wrong mode payments', function (string $path, mixed $value) {
    $gateway = paymongoTestGateway(['secret_key' => 'sk_test_example']);
    $checkout = paymongoPaidCheckout();
    data_set($checkout, $path, $value);

    expect($gateway->verifiedPayment($checkout, paymongoTestOrder()))->toBeNull();
})->with([
    ['id', 'cs_other'],
    ['attributes.reference_number', '43'],
    ['attributes.livemode', true],
    ['attributes.payments', []],
    ['attributes.payments.0.id', null],
    ['attributes.payments.0.attributes.status', 'pending'],
    ['attributes.payments.0.attributes.amount', 12549],
    ['attributes.payments.0.attributes.currency', 'USD'],
    ['attributes.payments.0.attributes.source.type', 'card'],
]);

it('does not offer GCash for unsupported currency, missing credentials or buy now', function (array $config, string $currency, bool $buyNow, bool $expected) {
    $gateway = paymongoTestGateway($config);
    $gateway->shouldReceive('getCart')->andReturn((object) ['cart_currency_code' => $currency, 'is_buy_now' => $buyNow]);

    expect($gateway->isAvailable())->toBe($expected);
})->with([
    [['active' => true, 'secret_key' => 'sk_test_example'], 'PHP', false, true],
    [['active' => true, 'secret_key' => 'sk_live_example'], 'PHP', false, true],
    [['active' => false, 'secret_key' => 'sk_test_example'], 'PHP', false, false],
    [['active' => true], 'PHP', false, false],
    [['active' => true, 'secret_key' => 'pk_test_example'], 'PHP', false, false],
    [['active' => true, 'secret_key' => 'sk_test_example'], 'USD', false, false],
    [['active' => true, 'secret_key' => 'sk_test_example'], 'PHP', true, false],
]);

// ============================================================================
// Webhook Authentication
// ============================================================================

it('accepts a fresh signed webhook for its configured mode', function (string $mode, string $field) {
    $gateway = paymongoTestGateway(['secret_key' => 'sk_'.$mode.'_example', 'webhook_secret' => 'whsec_example']);
    $body = '{"event":"paid"}';
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_example');

    expect($gateway->validSignature($body, 't='.$timestamp.','.$field.'='.$signature))->toBeTrue();
    expect($gateway->validSignature($body.' ', 't='.$timestamp.','.$field.'='.$signature))->toBeFalse();
})->with([['test', 'te'], ['live', 'li']]);

it('rejects missing, stale and wrong-mode signatures', function () {
    $gateway = paymongoTestGateway(['secret_key' => 'sk_test_example', 'webhook_secret' => 'whsec_example']);
    $body = '{}';
    $timestamp = time() - 301;
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_example');

    expect($gateway->validSignature($body, ''))->toBeFalse()
        ->and($gateway->validSignature($body, 't='.$timestamp.',te='.$signature))->toBeFalse()
        ->and($gateway->validSignature($body, 't='.time().',li='.$signature))->toBeFalse();
});
