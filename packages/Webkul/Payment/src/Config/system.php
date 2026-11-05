<?php

return [
    [
        'key' => 'sales.payment_methods.paymongo_gcash',
        'name' => 'payment::app.gcash',
        'info' => 'payment::app.info',
        'sort' => 11,
        'fields' => [
            [
                'name' => 'active',
                'title' => 'admin::app.configuration.index.sales.payment-methods.status',
                'type' => 'boolean',
                'channel_based' => true,
            ], [
                'name' => 'title',
                'title' => 'admin::app.configuration.index.sales.payment-methods.title',
                'type' => 'text',
                'depends' => 'active:1',
                'validation' => 'required_if:active,1',
                'channel_based' => true,
                'locale_based' => true,
            ], [
                'name' => 'description',
                'title' => 'admin::app.configuration.index.sales.payment-methods.description',
                'type' => 'textarea',
                'channel_based' => true,
                'locale_based' => true,
            ], [
                'name' => 'secret_key',
                'title' => 'payment::app.secret-key',
                'type' => 'password',
                'info' => 'payment::app.secret-key-info',
            ], [
                'name' => 'webhook_secret',
                'title' => 'payment::app.webhook-secret',
                'type' => 'password',
                'info' => 'payment::app.webhook-info',
            ], [
                'name' => 'sort',
                'title' => 'admin::app.configuration.index.sales.payment-methods.sort-order',
                'type' => 'text',
                'validation' => 'numeric',
                'channel_based' => true,
            ],
        ],
    ],
];
