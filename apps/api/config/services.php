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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'intelligence' => [
        'base_url' => env('INTELLIGENCE_BASE_URL', 'http://127.0.0.1:8080'),
        'service_token' => env('INTERNAL_SERVICE_TOKEN', ''),
        'timeout' => (int) env('INTELLIGENCE_TIMEOUT', 30),
    ],

    'ops' => [
        'auto_radar_enabled' => env('OPS_AUTO_RADAR_ENABLED', true),
        'radar_interval_minutes' => (int) env('OPS_RADAR_REFRESH_INTERVAL_MINUTES', 10),
        'radar_symbols' => env('OPS_RADAR_SYMBOLS', '300059,000001,002311,300687,601127'),
        'radar_limit' => (int) env('OPS_RADAR_LIMIT', 50),
    ],

];
