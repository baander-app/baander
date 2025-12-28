<?php

namespace App\Http\Controllers\Web;

use App\Events\Auth\{PasskeyAuthenticationFailedEvent, PasskeyRegisteredEvent, PasskeyUsedToAuthenticateEvent,};
use App\Http\Controllers\Api\Auth\Concerns\HandlesUserTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\{AuthenticateUsingPasskeyRequest, StorePasskeyRequest};
use App\Models\Auth\Passkey;
use App\Models\User;
use App\Modules\Auth\Webauthn\Actions\{FindPasskeyToAuthenticateAction,
    GeneratePasskeyAuthenticationOptionsAction,
    GeneratePasskeyRegisterOptionsAction,
    StorePasskeyAction};
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\{Auth, Cache, Event, Log, Session};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\RouteAttributes\Attributes\{Get, Post, Prefix};
use Throwable;

/**
 * WebAuthn passkey authentication controller
 *
 * Handles WebAuthn/FIDO2 passkey authentication including passkey registration,
 * authentication challenge generation, and secure login flows. Provides passwordless
 * authentication using biometric or hardware security keys.
 *
 * @tags Auth
 */
#[Prefix('/webauthn/passkey')]
#[Group('Auth')]
class PasskeyController extends Controller
{
    use HandlesUserTokens;

    public const string REGISTER_OPTIONS_SESSION_KEY = 'passkey-registration-options';

    /**
     * Generate WebAuthn authentication challenge
     *
     * Creates a cryptographic challenge for passkey authentication including
     * allowed credentials and relying party information. This challenge must
     * be used with the WebAuthn JavaScript API for authentication.
     *
     * @param Request $request Request from user attempting authentication
     *
     * @unauthenticated
     * @response array{
     *   challenge: string,
     *   rpId: string,
     *   allowCredentials: array<array{
     *     id: string,
     *     type: string,
     *     transports: array<string>
     *   }>,
     *   userVerification: string,
     *   timeout: int,
     *   challengeId: string
     * }
     */
    #[Get('/', 'auth.passkey.options')]
    public function getOptions(Request $request): JsonResponse
    {
        $action = new GeneratePasskeyAuthenticationOptionsAction();

        /** @var array $options WebAuthn authentication challenge options */
        $options = $action->execute($request->user());

        // Generate a unique challenge ID and store options in cache
        $challengeId = Str::random(40);
        Cache::put("passkey_challenge:{$challengeId}", json_encode($options), now()->addMinutes(5));

        // Store options in session for backward compatibility with web flow
        Session::put('passkey-authentication-options', $options);

        // WebAuthn authentication challenge for passkey login.
        return response()->json(array_merge($options, [
            'challengeId' => $challengeId,
        ]));
    }

    /**
     * Authenticate using WebAuthn passkey
     *
     * Verifies the WebAuthn assertion from the user's authenticator and logs them in
     * if successful. Creates session tokens and handles redirect logic for seamless
     * authentication experience.
     *
     * @param AuthenticateUsingPasskeyRequest $request Request containing WebAuthn assertion response
     *
     * @throws ValidationException When WebAuthn assertion is invalid
     * @unauthenticated
     * @response array{
     *   accessToken: NewAccessTokenResource,
     *   refreshToken: NewAccessTokenResource,
     *   sessionId: string
     * }|array{message: string}
     * @status 201
     */
    #[Post('/', 'auth.passkey.login')]
    public function authenticate(AuthenticateUsingPasskeyRequest $request): JsonResponse|RedirectResponse
    {
        $findAuthenticatableUsingPasskey = new FindPasskeyToAuthenticateAction();

        /** @var Passkey|null $passkey */
        $passkey = $findAuthenticatableUsingPasskey->execute(
            $request->get('start_authentication_response'),
            Session::get('passkey-authentication-options'),
        );

        if (!$passkey) {
            return $this->invalidPasskeyResponse();
        }

        /** @var User|null $authenticatable */
        $authenticatable = $passkey->user;

        if (!$authenticatable) {
            return $this->invalidPasskeyResponse();
        }

        // Log in the user and regenerate session
        $this->logInAuthenticatable($authenticatable);

        // Fire authentication event for logging/analytics
        event(new PasskeyUsedToAuthenticateEvent($passkey));

        // Log successful authentication
        Log::info('User authenticated with passkey', [
            'user_id'    => $authenticatable->id,
            'passkey_id' => $passkey->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Successful passkey authentication - return tokens or redirect.
        return $this->validPasskeyResponse($request, $authenticatable);
    }

    /**
     * Handle invalid passkey authentication response
     *
     * Returns a standardized error response when passkey authentication fails
     * due to invalid credentials or verification errors.
     *
     * @return JsonResponse Error response for invalid passkey
     */
    protected function invalidPasskeyResponse(): JsonResponse
    {
        // Log failed authentication attempt for security monitoring
        Log::warning('Invalid passkey authentication attempt', [
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp'  => now(),
        ]);

        // Fire failed authentication event
        Event::dispatch(new PasskeyAuthenticationFailedEvent(request(), 'invalid_credential'));

        // Invalid passkey authentication error.
        return response()->json([
            'message' => __('passkeys::passkeys.invalid'),
        ], 401);
    }

    /**
     * Log in the authenticated user and regenerate session
     *
     * Internal method to handle user login after successful passkey authentication.
     * Includes session regeneration for security purposes.
     *
     * @param Authenticatable $authenticatable The user to log in
     * @return self For method chaining
     */
    public function logInAuthenticatable(Authenticatable $authenticatable): self
    {
        // Authenticate the user
        Auth::login($authenticatable);

        // Regenerate session ID for security
        Session::regenerate();

        return $this;
    }

    /**
     * Handle successful passkey authentication response
     *
     * Determines the appropriate response after successful authentication,
     * either redirecting to a stored URL or returning authentication tokens
     * for API/SPA usage.
     *
     * @param Request $request The authentication request
     * @param User $user The authenticated user
     * @return JsonResponse|RedirectResponse Tokens or redirect response
     */
    public function validPasskeyResponse(Request $request, User $user): JsonResponse|RedirectResponse
    {
        /** @var string|null $redirectUrl Stored redirect URL from session */
        $redirectUrl = Session::has('passkeys.redirect')
            ? Session::pull('passkeys.redirect')
            : null;

        // If there's a stored redirect URL, redirect there
        if ($redirectUrl) {
            return redirect($redirectUrl);
        }

        // Otherwise, return authentication tokens for API/SPA usage
        return $this->createTokenSet($request, $user);
    }

    /**
     * Generate WebAuthn registration challenge for new passkey
     *
     * Creates a cryptographic challenge for registering a new passkey to the
     * authenticated user's account. The challenge includes user information
     * and credential creation parameters.
     *
     * @param Request $request Authenticated request from user
     *
     * @throws AuthorizationException When user is not authenticated
     * @response array{
     *   rp: array{
     *     name: string,
     *     id: string
     *   },
     *   user: array{
     *     id: string,
     *     name: string,
     *     displayName: string
     *   },
     *   challenge: string,
     *   pubKeyCredParams: array<array{
     *     type: string,
     *     alg: int
     *   }>,
     *   timeout: int,
     *   attestation: string,
     *   authenticatorSelection: array{
     *     authenticatorAttachment: string,
     *     userVerification: string,
     *     residentKey: string
     *   }
     * }
     */
    #[Get('/register', 'auth.passkey.register-option', ['auth:oauth'])]
    public function getRegisterOptions(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            abort(401, 'You must be logged in');
        }

        $action = new GeneratePasskeyRegisterOptionsAction();

        /** @var array $options WebAuthn registration challenge options */
        $options = $action->execute($user);

        // Store options in session for verification during registration
        Session::put('passkey-registration-options', $options);

        // WebAuthn registration challenge for new passkey.
        return $options;
    }

    /**
     * Register a new passkey for the authenticated user
     *
     * Processes the WebAuthn attestation response to register a new passkey
     * credential for the user's account. Includes validation and secure storage
     * of the credential with optional naming.
     *
     * @param StorePasskeyRequest $request Request containing WebAuthn attestation and passkey name
     *
     * @throws AuthorizationException When user is not authenticated
     * @throws ValidationException When attestation is invalid
     * @response array{message: string}|array{error: string}
     * @status 201
     */
    #[Post('/register', 'auth.passkey.register', ['auth:oauth'])]
    public function registerPasskey(StorePasskeyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action = new StorePasskeyAction();

        try {
            /** @var Passkey $passkey */
            $passkey = $action->execute(
                $user,
                $request->get('passkey'),
                $this->previouslyGeneratedPasskeyOptions(),
                $request->host(),
                ['name' => $request->get('name')],
            );

            // Log successful passkey registration
            Log::info('New passkey registered', [
                'user_id'      => $user->id,
                'passkey_id'   => $passkey->id,
                'passkey_name' => $request->get('name'),
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);

            // Fire passkey registration event
            Event::dispatch(new PasskeyRegisteredEvent($passkey, $user, $request->get('name'), $request));

            return response()->json([
                'message' => 'Passkey successfully stored',
            ], 201);

        } catch (Throwable $e) {
            Log::error('Could not store passkey', [
                'user_id'           => $user->id,
                'exception.message' => $e->getMessage(),
                'exception.code'    => $e->getCode(),
                'ip_address'        => $request->ip(),
                'user_agent'        => $request->userAgent(),
            ]);

            // Passkey registration failure response.
            return response()->json(['error' => 'Could not store passkey'], 500);
        }
    }

    /**
     * Retrieve and remove previously generated passkey registration options
     *
     * Internal method to get the WebAuthn registration options stored in the
     * session during the registration flow for verification purposes.
     *
     * @return string|null The stored registration options
     */
    protected function previouslyGeneratedPasskeyOptions(): ?string
    {
        return Session::pull(self::REGISTER_OPTIONS_SESSION_KEY);
    }
}
