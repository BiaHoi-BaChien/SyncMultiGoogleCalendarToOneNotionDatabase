<?php

return [
    'rp_id' => env('PASSKEY_RP_ID', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?? 'localhost'),
    'origin' => env('PASSKEY_ORIGIN', env('APP_URL', 'http://localhost')),
    'challenge_ttl' => env('PASSKEY_CHALLENGE_TTL', 300),
];
