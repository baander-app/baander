<?php

namespace App\Http\Controllers\Api\Auth;

use Exception;
use App\Http\Controllers\Api\Auth\Concerns\HandlesUserTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\{ForgotPasswordRequest, LoginRequest, LogoutRequest, RegisterRequest, ResetPasswordRequest};
use App\Http\Resources\Auth\NewAccessTokenResource;
use App\Http\Resources\User\UserResource;
use App\Jobs\Auth\RevokeTokenJob;
use App\Models\{PersonalAccessToken, TokenAbility, User};
use App\Modules\Auth\{GeoLocationService, TokenBindingService};
use App\Notifications\ForgotPasswordNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\{Carbon, Collection, Facades\Auth, Facades\Hash, Facades\Password};
use Illuminate\Validation\ValidationException;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Post, Prefix};
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

/**
 * Authentication and token management controller
 *
 * Handles user authentication, registration, token management, and security features
 * including device binding, IP tracking, and session management.
 *
 * @tags Auth
 */
#[Prefix('auth')]
class AuthController extends Controller
{
    use HandlesUserTokens;

    public function __construct(
        private readonly TokenBindingService $tokenBindingService,
        private readonly GeoLocationService  $geoLocationService,
    )
    {
    }

    /**
     * Authenticate user and create session
     *
     * Authenticates a user with email and password, creates access and refresh tokens
     * with device binding and location tracking for security purposes.
     *
     * @param LoginRequest $request Request containing email, password, and optional remember flag
     *
     * @throws ValidationException When credentials are invalid
     * @unauthenticated
     * @response array{
     *   accessToken: NewAccessTokenResource,
     *   refreshToken: NewAccessTokenResource,
     *   sessionId: string
     * }
     * @status 201
     */
    #[Post('login', 'auth.login')]
    public function login(LoginRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = User::whereEmail($request->input('email'))->first();

        if (!$user) {
            abort(401, 'Invalid credentials.');
        }

        $attempt = Auth::attempt(
            $request->only('email', 'password'),
            $request->filled('remember'),
        );

        if (!$attempt) {
            abort(401, 'Invalid credentials.');
        }

        // Authentication successful - create token set with security bindings.
        return $this->createTokenSetWithBinding($request, $user);
    }

    /**
     * Create a token set with security binding information
     *
     * Internal method to create access and refresh tokens with comprehensive
     * security binding including device fingerprinting and location tracking.
     */
    private function createTokenSetWithBinding(Request $request, User $user): JsonResponse
    {
        $sessionId = $this->tokenBindingService->generateSessionId();
        $fingerprint = $this->tokenBindingService->generateClientFingerprint($request);
        $locationData = $this->geoLocationService->getLocationData($request->ip());
        $device = PersonalAccessToken::prepareDeviceFromRequest($request);

        // Create access token with API and broadcasting abilities
        $accessToken = $user->createToken(
            name: 'access_token',
            abilities: [TokenAbility::ACCESS_API->value, TokenAbility::ACCESS_BROADCASTING->value],
            expiresAt: Carbon::now()->addMinutes(config('sanctum.access_token_expiration')),
            device: $device,
        );

        // Create refresh token with token issuance ability
        $refreshToken = $user->createToken(
            name: 'refresh_token',
            abilities: [TokenAbility::ISSUE_ACCESS_TOKEN->value],
            expiresAt: Carbon::now()->addMinutes(config('sanctum.refresh_token_expiration')),
            device: $device,
        );

        // Apply security bindings to both tokens
        $this->initializeTokenBinding($accessToken->accessToken, $request, $sessionId, $fingerprint, $locationData);
        $this->initializeTokenBinding($refreshToken->accessToken, $request, $sessionId, $fingerprint, $locationData);

        return response()->json([
            'accessToken'  => new NewAccessTokenResource($accessToken),
            'refreshToken' => new NewAccessTokenResource($refreshToken),
            'sessionId'    => $sessionId,
        ]);
    }

    /**
     * Initialize token binding data for new tokens
     *
     * Sets up comprehensive security binding data including fingerprints,
     * location information, and IP tracking for new tokens.
     */
    private function initializeTokenBinding(
        PersonalAccessToken $token,
        Request             $request,
        string              $sessionId,
        string              $fingerprint,
        array               $locationData,
    ): void
    {
        $token->update([
            'client_fingerprint' => $fingerprint,
            'ip_address'         => $request->ip(),
            'session_id'         => $sessionId,
            'country_code'       => $locationData['country_code'],
            'city'               => $locationData['city'],
            'ip_history'         => json_encode([[
                                                     'ip'        => $request->ip(),
                                                     'timestamp' => now()->toISOString(),
                                                     'location'  => $locationData,
                                                 ]], JSON_THROW_ON_ERROR),
            'ip_change_count'    => 0,
        ]);
    }

    /**
     * Refresh access token using refresh token
     *
     * Creates a new access token using a valid refresh token. Updates device
     * binding information and maintains session continuity.
     *
     * @param Request $request Request with refresh token in the Authorization header
     *
     * @throws AuthorizationException|\JsonException When refresh token is invalid
     * @response array{accessToken: NewAccessTokenResource}
     */
    #[Post('refreshToken', 'auth.refreshToken', ['auth:sanctum', 'ability:' . TokenAbility::ISSUE_ACCESS_TOKEN->value])]
    public function refreshToken(Request $request): Response
    {
        $device = PersonalAccessToken::prepareDeviceFromRequest($request);

        $accessToken = $request->user()->createToken(
            name: 'access_token',
            abilities: [TokenAbility::ACCESS_API->value, TokenAbility::ACCESS_BROADCASTING->value],
            expiresAt: Carbon::now()->addMinutes(config('sanctum.access_token_expiration')),
            device: $device,
        );

        // Update token binding data for refreshed token
        $this->updateTokenBinding($accessToken->accessToken, $request);

        return response([
            'accessToken' => new NewAccessTokenResource($accessToken),
        ]);
    }

    /**
     * Update token-binding data for existing tokens
     *
     * Updates security binding information when tokens are used, including
     * IP change tracking and location updates for security monitoring.
     */
    private function updateTokenBinding(PersonalAccessToken $token, Request $request): void
    {
        /** @var string|null $sessionId Session ID from request header */
        $sessionId = $request->header('X-Session-Id');
        if (!$sessionId) {
            return; // Skip if no session ID provided
        }

        $fingerprint = $this->tokenBindingService->generateClientFingerprint($request);
        $locationData = $this->geoLocationService->getLocationData($request->ip());

        $token->update([
            'client_fingerprint' => $fingerprint,
            'session_id'         => $sessionId,
        ]);

        if ($token->ip_address !== $request->ip()) {
            /** @var array $ipHistory Current IP history */
            $ipHistory = $token->ip_history ? json_decode($token->ip_history, true, 512, JSON_THROW_ON_ERROR) : [];

            $ipHistory[] = [
                'ip'        => $request->ip(),
                'timestamp' => now()->toISOString(),
                'location'  => $locationData,
            ];

            // Keep only last 10 IP entries
            $ipHistory = array_slice($ipHistory, -10);

            $token->update([
                'ip_address'      => $request->ip(),
                'ip_history'      => json_encode($ipHistory, JSON_THROW_ON_ERROR),
                'ip_change_count' => ($token->ip_change_count ?? 0) + 1,
                'country_code'    => $locationData['country_code'],
                'city'            => $locationData['city'],
            ]);
        }
    }

    /**
     * Create a stream-specific access token
     *
     * Generates a short-lived token specifically for media streaming operations.
     * These tokens have limited scope and shorter expiration for enhanced security.
     *
     * @param Request $request Request with refresh token for authorization
     *
     * @throws AuthorizationException When refresh token is invalid
     * @response array{streamToken: NewAccessTokenResource}
     */
    #[Post('streamToken', 'auth.streamToken', ['auth:sanctum', 'ability:' . TokenAbility::ISSUE_ACCESS_TOKEN->value])]
    public function getStreamToken(Request $request): JsonResponse
    {
        $device = PersonalAccessToken::prepareDeviceFromRequest($request);

        $streamToken = $request->user()->createToken(
            name: 'stream_token',
            abilities: [TokenAbility::ACCESS_STREAM->value],
            expiresAt: Carbon::now()->addMinutes(config('sanctum.stream_token_expiration')),
            device: $device,
        );

        // Update token binding data for stream token
        $this->updateTokenBinding($streamToken->accessToken, $request);

        return response()->json([
            'streamToken' => new NewAccessTokenResource($streamToken),
        ]);
    }

    /**
     * Register a new user account
     *
     * Creates a new user account with the provided information and automatically
     * logs them in with access and refresh tokens.
     *
     * @param RegisterRequest $request Request containing name, email, and password
     *
     * @throws ValidationException When registration data is invalid
     * @unauthenticated
     * @response array{
     *   accessToken: NewAccessTokenResource,
     *   refreshToken: NewAccessTokenResource,
     *   sessionId: string
     * }
     * @status 201
     */
    #[Post('register', 'auth.register')]
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::forceCreate([
            'name'     => $request->input('name'),
            'email'    => $request->input('email'),
            'password' => Hash::make($request->input('password')),
        ]);

        // Registration successful - create initial session tokens.
        return $this->createTokenSetWithBinding($request, $user);
    }

    /**
     * Get user's active sessions and tokens
     *
     * Returns detailed information about all active tokens/sessions including
     * IP history, location data, and device information for security management.
     *
     * @param Request $request Authenticated request
     *
     * @response array<array{
     *   id: int,
     *   name: string,
     *   ip_address: string,
     *   ip_change_count: int,
     *   country_code:string,
     *   city:string,
     *   ip_history: array,
     *   last_used_at: string,
     *   created_at: string,
     *   is_current: bool
     * }>
     */
    #[Get('tokens', 'auth.tokens', ['auth:sanctum'])]
    public function getTokens(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->get()->map(function ($token) use ($request) {
            return [
                'id'              => $token->id,
                'name'            => $token->name,
                'ip_address'      => $token->ip_address,
                'ip_change_count' => $token->ip_change_count ?? 0,
                'country_code'    => $token->country_code,
                'city'            => $token->city,
                'ip_history'      => json_decode($token->ip_history, true, 512, JSON_THROW_ON_ERROR) ?: [],
                'last_used_at'    => $token->last_used_at,
                'created_at'      => $token->created_at,
                'is_current'      => $token->id === $request->user()->currentAccessToken()?->id,
            ];
        });

        // Active session and token information.
        return response()->json($tokens);
    }

    /**
     * Revoke a specific token/session
     *
     * Permanently revokes a specific token, ending that session. Cannot be used
     * to revoke the current session - use logout endpoint instead.
     *
     * @param Request $request Authenticated request
     * @param string $tokenId The ID of the token to revoke
     *
     * @throws ModelNotFoundException When a token is not found
     * @throws ValidationException When trying to revoke the current token
     * @response array{message: string}
     */
    #[Delete('tokens/{token}', 'auth.tokens.revoke', ['auth:sanctum'])]
    public function revokeToken(Request $request, string $tokenId): JsonResponse
    {
        $token = $request->user()->tokens()->findOrFail($tokenId);

        // Don't allow revoking the current token via this endpoint
        if ($token->id === $request->user()->currentAccessToken()?->id) {
            return response()->json([
                'message' => 'Cannot revoke current session. Use logout instead.',
            ], 400);
        }

        $token->delete();

        return response()->json(['message' => 'Token revoked successfully']);
    }

    /**
     * Revoke all sessions except current
     *
     * Revokes all active tokens except the current session. Useful for security
     * purposes when user wants to log out all other devices.
     *
     * @param Request $request Authenticated request
     *
     * @response array{message: string}
     */
    #[Delete('tokens', 'auth.tokens.revokeAll', ['auth:sanctum'])]
    public function revokeAllTokensExceptCurrent(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()->id)
            ->delete();

        // Bulk token revocation confirmation.
        return response()->json(['message' => 'All tokens except current revoked successfully']);
    }

    /**
     * Request a password-reset link
     *
     * Sends a password reset link to the specified email address if a user
     * account exists. The link contains a secure token for verification.
     *
     * @param ForgotPasswordRequest $request Request containing email and optional URL template
     *
     * @throws ModelNotFoundException When user email is not found
     * @unauthenticated
     * @response array{message: string}
     */
    #[Post('forgotPassword', 'auth.forgotPassword')]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::query()->where($request->only('email'))->firstOrFail();
        $token = Password::createToken($user);

        $url = str_replace(
            ['{token}', '{email}'],
            [$token, $user->email],
            $request->input('url') ?? config('app.url') . '/password/reset?token={token}&email={email}',
        );

        // Send password reset notification
        new AnonymousNotifiable()
            ->route('mail', $user->email)
            ->notify(new ForgotPasswordNotification($url));

        // Password reset link sent confirmation.
        return response()->json(['message' => __('Reset password link sent to your email.')]);
    }

    /**
     * Reset user password
     *
     * Resets the user's password using a valid reset token. All existing
     * tokens are revoked for security after a password change.
     *
     * @param ResetPasswordRequest $request Request containing email, token, and new password
     *
     * @throws ModelNotFoundException When a user is not found
     * @throws ValidationException|\Throwable When a token is invalid
     * @unauthenticated
     * @response array{message: string}
     */
    #[Post('resetPassword', 'auth.resetPassword')]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user = (new User)->whereEmail($request->only('email'))->firstOrFail();

        if (!Password::getRepository()->exists($user, $request->input('token'))) {
            abort(400, 'Provided invalid token.');
        }

        $user->password = Hash::make($request->input('password'));
        $user->saveOrFail();

        Password::deleteToken($user);

        // Revoke all existing tokens when password is reset for security
        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset successfully.']);
    }

    /**
     * Verify the user email address
     *
     * Verifies a user's email address using the verification link sent during
     * registration or email change. Marks the email as verified.
     *
     * @param int $id User ID from verification URL
     * @param string $hash Verification hash from URL
     *
     * @throws ModelNotFoundException When a user is not found
     * @throws Exception When verification hash is invalid
     * @unauthenticated
     * @response UserResource
     */
    #[Post('verify/{id}/{hash}', 'auth.verifyEmail')]
    public function verify(int $id, string $hash): UserResource
    {
        /** @var User $user */
        $user = User::query()->findOrFail($id);

        if (method_exists($user, 'createToken') && !hash_equals($hash, sha1($user->getEmailForVerification()))) {
            throw new \RuntimeException('Invalid hash');
        }

        if ($user instanceof MustVerifyEmail && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        // Email verification successful - return user data.
        return new UserResource($user);
    }

    /**
     * Log out the current session
     *
     * Revokes the current access and refresh tokens, effectively logging out
     * the user from the current session/device.
     *
     * @param LogoutRequest $request Request with optional refresh token
     *
     * @status 204
     */
    #[Post('logout', 'auth.logout', ['auth:sanctum'])]
    public function logout(LogoutRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var string|null $accessToken Current access token */
        $accessToken = $user->currentAccessToken()->token ?? null;
        if ($accessToken) {
            RevokeTokenJob::dispatch($accessToken);
        }

        /** @var string|null $refreshToken Refresh token from request */
        $refreshToken = $request->get('refreshToken');
        if ($refreshToken) {
            RevokeTokenJob::dispatch($refreshToken);
        }

        // Logout successful - no content returned.
        return response(null, ResponseAlias::HTTP_NO_CONTENT);
    }
}
