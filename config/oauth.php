<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OAuth 2.0 Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the OAuth 2.0 authorization server including
    | token lifetimes, keys, and grant-specific settings.
    |
    */

    'private_key' => env('OAUTH_PRIVATE_KEY', storage_path('oauth-private.key')),
    'public_key' => env('OAUTH_PUBLIC_KEY', storage_path('oauth-public.key')),
    'encryption_key' => env('OAUTH_ENCRYPTION_KEY', env('APP_KEY')),

    /*
    |--------------------------------------------------------------------------
    | Token Lifetimes
    |--------------------------------------------------------------------------
    |
    | How long various tokens should remain valid. These are PHP DateInterval
    | format strings (e.g., PT1H = 1 hour, P1M = 1 month).
    |
    */

    'access_token_ttl' => env('OAUTH_ACCESS_TOKEN_TTL', 'PT1H'), // 1 hour
    'refresh_token_ttl' => env('OAUTH_REFRESH_TOKEN_TTL', 'P1M'), // 1 month
    'auth_code_ttl' => env('OAUTH_AUTH_CODE_TTL', 'PT10M'), // 10 minutes

    /*
    |--------------------------------------------------------------------------
    | Device Code Flow Settings
    |--------------------------------------------------------------------------
    |
    | Configuration specific to the device code flow (RFC 8628).
    |
    */

    'device_code_ttl' => env('OAUTH_DEVICE_CODE_TTL', 600), // 10 minutes in seconds
    'device_code_interval' => env('OAUTH_DEVICE_CODE_INTERVAL', 5), // 5 seconds
    'device_verification_uri' => env('OAUTH_DEVICE_VERIFICATION_URI', env('APP_URL') . '/device'),

    /*
    |--------------------------------------------------------------------------
    | Default Scopes
    |--------------------------------------------------------------------------
    |
    | Default OAuth scopes that will be created during installation.
    |
    */

    'default_scopes' => [
        'read' => 'Read access to user data',
        'write' => 'Write access to user data',
        'delete' => 'Delete access to user data',
        'stream' => 'Stream media content',
        'admin' => 'Administrative access',
    ],

    /*
    |--------------------------------------------------------------------------
    | Grant Types
    |--------------------------------------------------------------------------
    |
    | Which OAuth 2.0 grant types should be enabled.
    |
    */

    'grants' => [
        'authorization_code' => true,
        'client_credentials' => true,
        'password' => env('OAUTH_PASSWORD_GRANT_ENABLED', false),
        'refresh_token' => true,
        'device_code' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | PKCE (Proof Key for Code Exchange)
    |--------------------------------------------------------------------------
    |
    | Whether to enforce PKCE for public clients using authorization code flow.
    |
    */

    'require_code_challenge_for_public_clients' => env('OAUTH_REQUIRE_CODE_CHALLENGE', true),
];
