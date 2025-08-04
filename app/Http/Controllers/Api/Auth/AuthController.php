<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\Auth\Concerns\HandlesUserTokens;
use Exception;
use App\Http\Requests\Auth\{ForgotPasswordRequest, LoginRequest, LogoutRequest, RegisterRequest, ResetPasswordRequest};
use App\Http\Resources\Auth\NewAccessTokenResource;
use App\Http\Resources\User\UserResource;
use App\Jobs\Auth\RevokeTokenJob;
use Illuminate\Http\JsonResponse;
use App\Models\{PersonalAccessToken, TokenAbility, User};
use App\Modules\Auth\GeoLocationService;
use App\Modules\Auth\TokenBindingService;
use App\Notifications\ForgotPasswordNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Hash, Password};
use Spatie\RouteAttributes\Attributes\{Delete, Get, Post, Prefix};
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

/**
 * @tags Auth
 */
#[Prefix('auth')]
class AuthController
{
    use HandlesUserTokens;

    public function __construct(
        private readonly TokenBindingService $tokenBindingService,
        private readonly GeoLocationService  $geoLocationService,
    )
    {
    }


    /**
     * Login
     * @unauthenticated
     *
     * @response array{
     *   accessToken: NewAccessTokenResource,
     *   refreshToken: NewAccessTokenResource,
     *   sessionId: string
     * }
     */
    #[Post('login', 'auth.login')]
    public function login(LoginRequest $request)
    {
        $user = (new \App\Models\User)->whereEmail($request->input('email'))->first();

        if (!$user) {
            abort(401, 'Invalid credentials.');
        }

        $attempt = auth()->attempt($request->only('email', 'password'), $request->filled('remember'));

        if (!$attempt) {
            abort(401, 'Invalid credentials.');
        }

        return $this->createTokenSetWithBinding($request, $user);
    }

    /**
     * Refresh token
     *
     * Needs refresh token with ability "issue-access-token"
     */
    #[Post('refreshToken', 'auth.refreshToken', ['auth:sanctum', 'ability:' . TokenAbility::ISSUE_ACCESS_TOKEN->value])]
    public function refreshToken(Request $request)
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
     * Get a stream token
     *
     * Needs refresh token with ability "issue-access-token"
     *
     * @return JsonResponse
     * @response array{
     *     streamToken: NewAccessTokenResource,
     * }
     */
    #[Post('streamToken', 'auth.streamToken', ['auth:sanctum', 'ability:' . TokenAbility::ISSUE_ACCESS_TOKEN->value])]
    public function getStreamToken(Request $request)
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
     * Register
     * @unauthenticated
     *
     * @response array{
     *   accessToken: NewAccessTokenResource,
     *   refreshToken: NewAccessTokenResource,
     *   sessionId: string
     * }
     */
    #[Post('register', 'auth.register')]
    public function register(RegisterRequest $request)
    {
        $user = (new \App\Models\User)->forceCreate([
            'name'     => $request->input('name'),
            'email'    => $request->input('email'),
            'password' => Hash::make($request->input('password')),
        ]);

        return $this->createTokenSetWithBinding($request, $user);
    }

    /**
     * Get user's active tokens/sessions
     *
     * Returns information about all active tokens including IP history
     */
    #[Get('tokens', 'auth.tokens', ['auth:sanctum'])]
    public function getTokens(Request $request)
    {
        $tokens = $request->user()->tokens()->get()->map(function ($token) use ($request) {
            return [
                'id'              => $token->id,
                'name'            => $token->name,
                'ip_address'      => $token->ip_address,
                'ip_change_count' => $token->ip_change_count ?? 0,
                'country_code'    => $token->country_code,
                'city'            => $token->city,
                'ip_history'      => json_decode($token->ip_history, true) ?: [],
                'last_used_at'    => $token->last_used_at,
                'created_at'      => $token->created_at,
                'is_current'      => $token->id === $request->user()->currentAccessToken()?->id,
            ];
        });

        return response()->json($tokens);
    }

    /**
     * Revoke a specific token
     */
    #[Delete('tokens/{token}', 'auth.tokens.revoke', ['auth:sanctum'])]
    public function revokeToken(Request $request, $tokenId)
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
     * Revoke all tokens except current
     */
    #[Delete('tokens', 'auth.tokens.revokeAll', ['auth:sanctum'])]
    public function revokeAllTokensExceptCurrent(Request $request)
    {
        $user = $request->user();
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'All tokens except current revoked successfully']);
    }

    /**
     * Request reset password link
     * @unauthenticated
     */
    #[Post('forgotPassword', 'auth.forgotPassword')]
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $user = User::query()->where($request->only('email'))->firstOrFail();

        $token = Password::createToken($user);

        $url = str_replace(
            ['{token}', '{email}'],
            [$token, $user->email],
            $request->input('url') ?? config('app.url') . '/password/reset?token={token}&email={email}',
        );


        new AnonymousNotifiable()->route('mail', $user->email)->notify(new ForgotPasswordNotification($url));

        return response()->json(['message' => __('Reset password link sent to your email.')]);
    }

    /**
     * Reset password
     * @unauthenticated
     */
    #[Post('resetPassword', 'auth.resetPassword')]
    public function resetPassword(ResetPasswordRequest $request)
    {
        $user = (new \App\Models\User)->whereEmail($request->only('email'))->firstOrFail();

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
     * Verify email
     * @unauthenticated
     */
    #[Post('verify/{id}/{hash}', 'auth.verifyEmail')]
    public function verify(int $id, string $hash)
    {
        $user = User::query()->findOrFail($id);

        if (method_exists($user, 'createToken') && !hash_equals($hash, sha1($user->getEmailForVerification()))) {
            throw new Exception('Invalid hash');
        }

        if ($user instanceof MustVerifyEmail && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return new UserResource($user);
    }

    /**
     * Logout
     *
     * Invalidates the current session
     */
    #[Post('logout', 'auth.logout', ['auth:sanctum'])]
    public function logout(LogoutRequest $request)
    {
        $accessToken = $request->user()->currentAccessToken()->token;
        if ($accessToken) {
            RevokeTokenJob::dispatch($accessToken);
        }

        $refreshToken = $request->get('refreshToken');
        if ($refreshToken) {
            RevokeTokenJob::dispatch($refreshToken);
        }

        return response(null, ResponseAlias::HTTP_NO_CONTENT);
    }

    /**
     * Create token set with binding information
     */
    private function createTokenSetWithBinding(Request $request, User $user)
    {
        $sessionId = $this->tokenBindingService->generateSessionId();
        $fingerprint = $this->tokenBindingService->generateClientFingerprint($request);
        $locationData = $this->geoLocationService->getLocationData($request->ip());

        $device = PersonalAccessToken::prepareDeviceFromRequest($request);

        $accessToken = $user->createToken(
            name: 'access_token',
            abilities: [TokenAbility::ACCESS_API->value, TokenAbility::ACCESS_BROADCASTING->value],
            expiresAt: Carbon::now()->addMinutes(config('sanctum.access_token_expiration')),
            device: $device,
        );

        $refreshToken = $user->createToken(
            name: 'refresh_token',
            abilities: [TokenAbility::ISSUE_ACCESS_TOKEN->value],
            expiresAt: Carbon::now()->addMinutes(config('sanctum.refresh_token_expiration')),
            device: $device,
        );

        // Update both tokens with binding data
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
                                                 ]]),
            'ip_change_count'    => 0,
        ]);
    }

    /**
     * Update token binding data for existing tokens
     */
    private function updateTokenBinding(PersonalAccessToken $token, Request $request): void
    {
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

        // Update IP data if changed
        if ($token->ip_address !== $request->ip()) {
            $ipHistory = $token->ip_history ? json_decode($token->ip_history, true) : [];

            $ipHistory[] = [
                'ip'        => $request->ip(),
                'timestamp' => now()->toISOString(),
                'location'  => $locationData,
            ];

            // Keep only last 10 IP entries
            $ipHistory = array_slice($ipHistory, -10);

            $token->update([
                'ip_address'      => $request->ip(),
                'ip_history'      => json_encode($ipHistory),
                'ip_change_count' => ($token->ip_change_count ?? 0) + 1,
                'country_code'    => $locationData['country_code'],
                'city'            => $locationData['city'],
            ]);
        }
    }
}