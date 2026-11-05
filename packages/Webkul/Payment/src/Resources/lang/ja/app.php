<?php

return [
    'gcash' => 'GCash (PayMongo)',
    'info' => 'Accept GCash payments through PayMongo. Available in regular cart checkout with PHP currency.',
    'secret-key' => 'PayMongo Secret Key',
    'secret-key-info' => 'Use sk_test_ for testing or sk_live_ for live payments. Keep this key private. This account is shared across channels.',
    'webhook-secret' => 'PayMongo Webhook Signing Secret',
    'webhook-info' => 'Subscribe to checkout_session.payment.paid at https://your-store-domain/paymongo/webhook. Enter its signing secret here. Local testing requires a public HTTPS tunnel.',
    'failed' => 'GCash checkout could not be started. Please try again or choose another payment method.',
    'pending' => 'Your GCash payment is awaiting confirmation. Please check your order before making another payment.',
    'canceled' => 'GCash checkout was canceled. Your cart has been restored.',
];
