<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
        'email' => [
            'api_url' => env('POSTMARK_API_URL', 'https://api.postmarkapp.com'),
            'timeout' => (int) env('POSTMARK_TIMEOUT', 8),
            'connect_timeout' => (int) env('POSTMARK_CONNECT_TIMEOUT', 3),
            'webhook_username' => env('POSTMARK_WEBHOOK_USERNAME', 'postmark'),
        ],
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v22.0'),
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 8),
        'connect_timeout' => (int) env('WHATSAPP_CONNECT_TIMEOUT', 3),
    ],

    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v22.0'),
        'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),
        'timeout' => (int) env('META_TIMEOUT', 8),
        'connect_timeout' => (int) env('META_CONNECT_TIMEOUT', 3),
    ],

    'telegram' => [
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
        'timeout' => (int) env('TELEGRAM_TIMEOUT', 8),
        'connect_timeout' => (int) env('TELEGRAM_CONNECT_TIMEOUT', 3),
    ],

    'twilio' => [
        'sms' => [
            'api_url' => env('TWILIO_API_URL', 'https://api.twilio.com'),
            'timeout' => (int) env('TWILIO_TIMEOUT', 8),
            'connect_timeout' => (int) env('TWILIO_CONNECT_TIMEOUT', 3),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_url' => env('STRIPE_API_URL', 'https://api.stripe.com/v1'),
        'timeout' => (int) env('STRIPE_TIMEOUT', 15),
        'connect_timeout' => (int) env('STRIPE_CONNECT_TIMEOUT', 5),
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
    ],

];
