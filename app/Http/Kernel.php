<?php

namespace App\Http;

use App\Http\Middleware\CheckOAuthScopes;
use App\Http\Middleware\ConvertQueryTokenToHeaderMiddleware;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\OpenTelemetryRootSpan;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidateOAuthToken;
use App\Http\Middleware\VerifyCsrfToken;
use App\Modules\OpenTelemetry\Middleware\HttpInstrumentationMiddleware;
use App\Modules\OpenTelemetry\Middleware\TracerIdMiddleware;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        OpenTelemetryRootSpan::class,
        ConvertEmptyStringsToNull::class,
        HttpInstrumentationMiddleware::class,
        TracerIdMiddleware::class,
        //        \App\Http\Middleware\SecurityHeadersMiddleware::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            //            \App\Http\Middleware\AddContentSecurityPolicyHeaders::class,
            SubstituteBindings::class,
        ],

        'api' => [
            EnsureFrontendRequestsAreStateful::class,
            ConvertQueryTokenToHeaderMiddleware::class,
            ThrottleRequests::class . ':api',
            SubstituteBindings::class,
        ],

        'public-api' => [
            // \Illuminate\Routing\Middleware\ThrottleRequests::class . ':publicApi',
            SubstituteBindings::class,
        ],
    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to conveniently assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        'abilities'        => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        'ability'          => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        'auth'             => \App\Http\Middleware\Authenticate::class,
        'auth.basic'       => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session'     => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers'    => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can'              => \Illuminate\Auth\Middleware\Authorize::class,
        'cors.policy'      => \App\Http\Middleware\AddCorsPolicyHeaders::class,
        'force.json'       => \App\Http\Middleware\ForceJsonResponse::class,
        'guest'            => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'precognitive'     => \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        'signed'           => \App\Http\Middleware\ValidateSignature::class,
        'throttle'         => ThrottleRequests::class,
        'verified'         => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'oauth'            => ValidateOAuthToken::class,
        'oauth.scopes'     => CheckOAuthScopes::class,

    ];
}
