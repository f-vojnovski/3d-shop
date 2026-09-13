<?php

return [

    'payments' => [
        'enabled' => env('PAYMENTS_ENABLED', false),
        // Signs the fake provider's webhook, which is what runs when no real
        // credentials are configured.
        'webhook_secret' => env('PAYMENTS_WEBHOOK_SECRET', 'whsec_test'),
    ],

    'paypal' => [
        // Sandbox by default: live needs a deliberate change.
        'base' => env('PAYPAL_BASE', 'https://api-m.sandbox.paypal.com'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],

    // Basis points, so 1500 is 15%. Integers all the way down.
    'commission' => [
        'bps' => env('COMMISSION_BPS', 1500),
    ],

];
