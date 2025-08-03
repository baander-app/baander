<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option controls the default authentication "guard" and password
    | reset options for your application. You may change these defaults
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard'     => 'web',
        'passwords' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | here which uses session storage and the Eloquent user provider.
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver'   => 'sanctum',
            'provider' => 'users',
            'hash'     => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | If you have multiple user tables or models you may configure multiple
    | sources which represent each model / table. These sources may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver'            => 'eloquent',
            'model'             => App\Models\User::class,
            'password_fallback' => true,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | You may specify multiple password reset configurations if you have more
    | than one user table or model in the application and you want to have
    | separate password reset settings based on the specific user types.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => 'password_reset_tokens',
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the amount of seconds before a password confirmation
    | times out and the user is prompted to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout'                  => 10800,


    /*
    |--------------------------------------------------------------------------
    | Use signed email verification URL
    |--------------------------------------------------------------------------
    |
    | Whether or not to sign the email verification URL
    | like the standard Laravel implementation does.
    | If set to `true`, additional `expires` and `signature` parameters
    | will be added to the URL. When verifying the email through the API
    | both those fields are required as well.
    | It defaults to `false` for backwards compatibility.
    |
    */
    'use_signed_email_verification_url' => true,

    /*
    |--------------------------------------------------------------------------
    | Identification
    |--------------------------------------------------------------------------
    |
    | Configure the credential fields by which the user will be identified.
    | Default: email
    */

    'user_identifier_field_name' => 'email',

    'email_verification_link_expires_in_minutes' => 60,

    'token_binding' => [
        'max_ip_changes' => 10,
        'geo_change_cooldown_seconds' => 3600, // 1 hour
        'concurrent_ip_window_seconds' => 300, // 5 minutes
        'max_concurrent_ips' => 1, // Only allow 1 concurrent IP (strict)
        'min_ip_change_interval_minutes' => 5, // Minimum 5 minutes between IP changes
        'suspicious_geo_jump_hours' => 2, // Country changes within 2 hours are suspicious
    ],
];
