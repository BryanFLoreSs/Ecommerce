<?php

use Webkul\Payment\Payment\CashOnDelivery;
use Webkul\Payment\Payment\MoneyTransfer;
use Webkul\Payment\Payment\Paymongo;

return [
    'paymongo_gcash' => [
        'class' => Paymongo::class,
        'code' => 'paymongo_gcash',
        'title' => 'GCash',
        'description' => 'Pay securely with GCash through PayMongo.',
        'active' => false,
        'sort' => 10,
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
    ],

    'cashondelivery' => [
        'class' => CashOnDelivery::class,
        'code' => 'cashondelivery',
        'title' => 'Cash On Delivery',
        'description' => 'Cash On Delivery',
        'active' => true,
        'generate_invoice' => false,
        'sort' => 7,
    ],

    'moneytransfer' => [
        'class' => MoneyTransfer::class,
        'code' => 'moneytransfer',
        'title' => 'Money Transfer',
        'description' => 'Money Transfer',
        'active' => true,
        'generate_invoice' => false,
        'sort' => 8,
    ],
];
