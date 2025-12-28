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
    | Supported: "session", "oauth"
    |
    */

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        'oauth' => [
            'driver'   => 'oauth',
            'provider' => 'users',
        ],

        'api' => [
            'driver'   => 'oauth',
            'provider' => 'users',
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
    | The 'password_fallback' option allows the authentication system to
    | fall back to password-based authentication when other methods fail.
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
            'expire'   => env('PASSWORD_RESET_EXPIRE', 60),
            'throttle' => env('PASSWORD_RESET_THROTTLE', 60),
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

    'password_timeout' => env('PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | Email Verification Settings
    |--------------------------------------------------------------------------
    |
    | Configure email verification behavior for your application.
    |
    */

    /*
    | Use Signed Email Verification URL
    |
    | Whether or not to sign the email verification URL like the standard
    | Laravel implementation does. If set to `true`, additional `expires`
    | and `signature` parameters will be added to the URL. When verifying
    | the email through the API both those fields are required as well.
    | It defaults to `false` for backwards compatibility.
    */
    'use_signed_email_verification_url' => true,

    /*
    | Email Verification Link Expiration
    |
    | The number of minutes that email verification links remain valid.
    | After this time expires, users will need to request a new verification link.
    */
    'email_verification_link_expires_in_minutes' => 60,

    /*
    |--------------------------------------------------------------------------
    | User Identification
    |--------------------------------------------------------------------------
    |
    | Configure the credential field by which users will be identified during
    | authentication. This field is used for login and user lookup operations.
    |
    | Default: email
    |
    */

    'user_identifier_field_name' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Token Binding Security
    |--------------------------------------------------------------------------
    |
    | These settings control token binding security measures to prevent
    | token abuse and detect suspicious authentication patterns. These
    | settings help protect against account takeovers and token theft.
    |
    */

    'token_binding' => [
        /*
        | Maximum IP Address Changes
        |
        | The maximum number of IP address changes allowed for a token
        | before it is considered suspicious and additional verification
        | may be required.
        */
        'max_ip_changes' => env('TOKEN_BINDING_MAX_IP_CHANGES', 10),

        /*
        | Geographic Change Cooldown
        |
        | The number of seconds a user must wait after a geographic location
        | change before they can authenticate from another location. This
        | helps prevent rapid geographic token abuse.
        */
        'geo_change_cooldown_seconds' => (int)env('TOKEN_BINDING_GEO_CHANGE_COOLDOWN_SECONDS', 3600), // 1 hour

        /*
        | Concurrent IP Time Window
        |
        | The time window in seconds during which concurrent IP usage is
        | monitored. IPs used within this window are considered concurrent.
        */
        'concurrent_ip_window_seconds' => (int)env('TOKEN_BINDING_CONCURRENT_IP_WINDOWS_SECONDS', 300), // 5 minutes

        /*
        | Maximum Concurrent IPs
        |
        | The maximum number of IP addresses that can be used concurrently
        | with the same token. Setting this to 1 enforces strict single-IP
        | usage, which provides maximum security but may impact user experience.
        */
        'max_concurrent_ips' => (int)env('TOKEN_BINDING_MAX_CONCURRENT_IPS', 1), // Only allow 1 concurrent IP (strict)

        /*
        | Minimum IP Change Interval
        |
        | The minimum number of minutes that must pass between IP address
        | changes for the same token. This prevents rapid IP switching.
        */
        'min_ip_change_interval_minutes' => (int)env('TOKEN_BINDING_MIN_IP_CHANGE_INTERVAL_MINUTES', 5), // Minimum 5 minutes between IP changes

        /*
        | Suspicious Geographic Jump Detection
        |
        | The number of hours within which country changes are considered
        | suspicious. Geographic changes within this timeframe will trigger
        | additional security measures.
        */
        'suspicious_geo_jump_hours' => (int)env('TOKEN_BINDING_SUSPICIOUS_GEO_JUMP_HOURS', 2), // Country changes within 2 hours are suspicious
    ],
];
