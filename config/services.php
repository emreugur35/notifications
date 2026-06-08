<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
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

    /*
    | Delivery provider — webhook.site endpoint the SendNotification job POSTs
    | { to, channel, content } to. Timeouts are in seconds.
    */
    'webhook' => [
        'url' => env('WEBHOOK_URL', 'https://webhook.site'),
        'timeout' => env('WEBHOOK_TIMEOUT', 10),
        'connect_timeout' => env('WEBHOOK_CONNECT_TIMEOUT', 5),
    ],

    /*
    | Per-channel circuit breaker: opens after `threshold` consecutive failures
    | (within `failure_ttl` seconds of each other) and stays open for `cooldown`
    | seconds.
    */
    'circuit_breaker' => [
        'threshold' => env('CIRCUIT_BREAKER_THRESHOLD', 5),
        'cooldown' => env('CIRCUIT_BREAKER_COOLDOWN', 60),
        'failure_ttl' => env('CIRCUIT_BREAKER_FAILURE_TTL', 120),
    ],

];
