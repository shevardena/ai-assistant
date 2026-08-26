<?php

return [
    'currency' => env('BILLING_CURRENCY', 'usd'),
    'plans' => [
        'starter' => [
            'stripe_price_id' => env('STRIPE_PRICE_STARTER'),
            'display_price' => env('BILLING_PRICE_STARTER'),
        ],
        'pro' => [
            'stripe_price_id' => env('STRIPE_PRICE_PRO'),
            'display_price' => env('BILLING_PRICE_PRO'),
        ],
        'business' => [
            'stripe_price_id' => env('STRIPE_PRICE_BUSINESS'),
            'display_price' => env('BILLING_PRICE_BUSINESS'),
        ],
    ],
];
