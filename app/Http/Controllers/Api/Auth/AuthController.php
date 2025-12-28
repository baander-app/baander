<?php

namespace App\Http\Controllers\Api\Auth;

use App\Events\Auth\{
    LoginFailedEvent,
    PasswordResetEvent,
    PasswordResetRequestedEvent,
    TokenIssuedEvent,
    TokenRefreshedEvent,
    TokenRevokedEvent,
    UserLoginEvent,
    UserLogoutEvent,
    UserRegisteredEvent,
};
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\{ForgotPasswordRequest, LoginRequest, LogoutRequest, RegisterRequest, ResetPasswordRequest};
use App\Http\Resources\User\UserResource;
use App\Models\User;
use App\Modules\Auth\{OAuthTokenService, TokenBindingService};
use App\Notifications\ForgotPasswordNotification;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\{Auth, Event, Hash, Password};
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
#[Group('Auth')]
class AuthController extends Controller
{
    public function __construct(
        private readonly OAuthTokenService   $oauthTokenService,
        private readonly TokenBindingService $tokenBindingService,
    )
    {
    }

    private const string SCOPE_ACCESS_API = 'access-api';
    private const string SCOPE_ACCESS_BROADCASTING = 'access-broadcasting';
    private const string SCOPE_ISSUE_ACCESS_TOKEN = 'issue-access-token';

    /**
     * Authenticate user and create session
     *
     * Authenticates a user with email and password, creates access and refresh tokens
     * with device binding and location tracking for security purposes.
     *
     * @param LoginRequest $request Request containing email, password, and optional remember flag
     *
     * @unauthenticated
     * @response array{
     *  access_token: string,
     *  refresh_token: string|null,
     *  expires_in: int,
     *  session_id: string
     *  }
     * @status 201
     */
    #[Post('login', 'auth.login')]
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::whereEmail($request->input('email'))->first();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            Event::dispatch(new LoginFailedEvent($request->input('email'), $request));
            Event::dispatch(401, 'Invalid credentials.');
        }

        // Generate session and fingerprint for security bindings
        $sessionId = $this->tokenBindingService->generateSessionId();
        $fingerprint = $this->tokenBindingService->generateClientFingerprint($request);

        // Create OAuth tokens with metadata
        $tokens = $this->oauthTokenService->createTokenSet(
            $request,
            $user,
            [self::SCOPE_ACCESS_API, self::SCOPE_ACCESS_BROADCASTING],
            $sessionId,
            $fingerprint,
        );

        // Fire login event
        Event::dispatch(new UserLoginEvent($user, $request, $sessionId));

        return response()->json([
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in'    => $tokens['expires_in'],
            'session_id'    => $sessionId,
        ], 201);
    }

    /**
     * Refresh access token using refresh token
     *
     * Creates a new access token using a valid refresh token.
     *
     * @param Request $request Request with refresh token in the Authorization header
     *
     * @response array{
     *  access_token: string,
     *  expires_in: int
     * }
     */
    #[Post('refreshToken', 'auth.refreshToken', ['auth:oauth', 'scope:' . self::SCOPE_ISSUE_ACCESS_TOKEN])]
    public function refreshToken(Request $request): JsonResponse
    {
        $refreshToken = $request->input('refresh_token');

        if (!$refreshToken) {
            abort(400, 'Refresh token is required.');
        }

        try {
            $tokens = $this->oauthTokenService->refreshToken($request, $refreshToken);
        } catch (\RuntimeException $e) {
            // Handle token reuse detection and other token errors
            if (str_contains($e->getMessage(), 'already been used')) {
                // Token reuse detected - return 401 to force re-login
                abort(401, 'Refresh token was reused. For security, please log in again.');
            }

            // Other token errors
            abort(401, $e->getMessage());
        }

        return response()->json($tokens);
    }

    /**
     * Register a new user account
     *
     * Creates a new user account with the provided information and automatically
     * logs them in with access and refresh tokens.
     *
     * @param RegisterRequest $request Request containing name, email, and password
     *
     * @unauthenticated
     * @response array{
     *  access_token: string,
     *  refresh_token: string|null,
     *  session_id: string
     *  }
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

        // Generate session and fingerprint
        $sessionId = $this->tokenBindingService->generateSessionId();
        $fingerprint = $this->tokenBindingService->generateClientFingerprint($request);

        // Create OAuth tokens with metadata
        $tokens = $this->oauthTokenService->createTokenSet(
            $request,
            $user,
            [self::SCOPE_ACCESS_API, self::SCOPE_ACCESS_BROADCASTING],
            $sessionId,
            $fingerprint,
        );

        // Fire registration event
        Event::dispatch(new UserRegisteredEvent($user, $request, $sessionId));

        return response()->json([
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'session_id'    => $sessionId,
        ], 201);
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
     *   token_id: string,
     *   name: string,
     *   scopes: array,
     *   ip_address: string,
     *   ip_change_count: int,
     *   country_code: string,
     *   city: string,
     *   ip_history: array,
     *   created_at: string,
     *   expires_at: string
     * }>
     */
    #[Get('tokens', 'auth.tokens', ['auth:oauth'])]
    public function getTokens(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->with('metadata')
            ->get()
            ->map(function ($token) {
                return [
                    'id'              => $token->id,
                    'token_id'        => $token->token_id,
                    'name'            => $token->name,
                    'scopes'          => $token->scopes,
                    'ip_address'      => $token->metadata?->ip_address,
                    'ip_change_count' => $token->metadata?->ip_change_count ?? 0,
                    'country_code'    => $token->metadata?->country_code,
                    'city'            => $token->metadata?->city,
                    'ip_history'      => $token->metadata?->ip_history ?? [],
                    'created_at'      => $token->created_at,
                    'expires_at'      => $token->expires_at,
                ];
            });

        return response()->json($tokens);
    }

    /**
     * Revoke a specific token/session
     *
     * Permanently revokes a specific token, ending that session.
     *
     * @param Request $request Authenticated request
     * @param string $tokenId The ID of the token to revoke
     *
     * @response array{message: string}
     */
    #[Delete('tokens/{token}', 'auth.tokens.revoke', ['auth:oauth'])]
    public function revokeToken(Request $request, string $tokenId): JsonResponse
    {
        $token = $request->user()->tokens()->findOrFail($tokenId);
        $token->revoke();

        Event::dispatch(new TokenRevokedEvent($request->user(), $token, 'user_requested'));

        return response()->json(['message' => 'Token revoked successfully']);
    }

    /**
     * Revoke all sessions except current
     *
     * Revokes all active tokens except the current session.
     *
     * @param Request $request Authenticated request
     *
     * @response array{message: string}
     */
    #[Delete('tokens', 'auth.tokens.revokeAll', ['auth:oauth'])]
    public function revokeAllTokensExceptCurrent(Request $request): JsonResponse
    {
        $currentTokenId = Auth::guard('oauth')->token()?->id;

        $request->user()
            ->tokens()
            ->where('id', '!=', $currentTokenId)
            ->update(['revoked' => true]);

        return response()->json(['message' => 'All tokens except current revoked successfully']);
    }

    /**
     * Request a password-reset link
     *
     * Sends a password reset link to the specified email address if a user
     * account exists.
     *
     * @param ForgotPasswordRequest $request Request containing email and optional URL template
     *
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

        new AnonymousNotifiable()
            ->route('mail', $user->email)
            ->notify(new ForgotPasswordNotification($url));

        Event::dispatch(new PasswordResetRequestedEvent($user, $token, $request));

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
     * @unauthenticated
     * @response array{message: string}
     */
    #[Post('resetPassword', 'auth.resetPassword')]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user = User::whereEmail($request->input('email'))->firstOrFail();

        if (!Password::getRepository()->exists($user, $request->input('token'))) {
            abort(400, 'Provided invalid token.');
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();

        Password::deleteToken($user);

        // Revoke all existing tokens when password is reset for security
        $user->tokens()->update(['revoked' => true]);

        Event::dispatch(new PasswordResetEvent($user, $request));

        return response()->json(['message' => 'Password reset successfully.']);
    }

    /**
     * Verify the user email address
     *
     * Verifies a user's email address using the verification link sent during
     * registration or email change.
     *
     * @param int $id User ID from verification URL
     * @param string $hash Verification hash from URL
     *
     * @unauthenticated
     * @response UserResource
     */
    #[Post('verify/{id}/{hash}', 'auth.verifyEmail')]
    public function verify(int $id, string $hash): UserResource
    {
        $user = User::query()->findOrFail($id);

        if (!hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403, 'Invalid verification link.');
        }

        if ($user instanceof MustVerifyEmail && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return new UserResource($user);
    }

    /**
     * Log out the current session
     *
     * Revokes the current access token, effectively logging out the user.
     *
     * @param LogoutRequest $request Request with optional refresh token
     *
     * @status 204
     */
    #[Post('logout', 'auth.logout', ['auth:oauth'])]
    public function logout(LogoutRequest $request): Response
    {
        $token = Auth::guard('oauth')->token();

        if ($token) {
            $token->revoke();
            Event::dispatch(new UserLogoutEvent($request->user(), $token));
        }

        return response(null, ResponseAlias::HTTP_NO_CONTENT);
    }
}
