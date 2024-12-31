<?php

namespace App\Http\Controllers\Api\Auth;

use App\Auth\TokenName;
use App\Http\Controllers\Api\Auth\Concerns\HandlesUserTokens;
use App\Jobs\Auth\RevokeTokenJob;
use App\Http\Requests\Auth\{ForgotPasswordRequest, LoginRequest, LogoutRequest, RegisterRequest, ResetPasswordRequest};
use App\Http\Resources\Auth\NewAccessTokenResource;
use App\Http\Resources\User\UserResource;
use Illuminate\Http\Response;
use App\Models\{PersonalAccessToken, TokenAbility, User};
use App\Notifications\ForgotPasswordNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Hash, Password};
use Spatie\RouteAttributes\Attributes\{Post, Prefix};
use Queue;

/**
 * @tags Auth
 */
#[Prefix('auth')]
class AuthController
{
    use HandlesUserTokens;

    /**
     * Login
     * @unauthenticated
     *
     * @response array{
     *   accessToken: string,
     *   refreshToken: string
     * }
     */
    #[Post('login', 'auth.login')]
    public function login(LoginRequest $request)
    {
        $user = User::whereEmail($request->input('email'))->first();

        if (!$user) {
            abort(401, 'Invalid credentials.');
        }

        $attempt = auth()->attempt($request->only('email', 'password'), $request->filled('remember'));

        if (!$attempt) {
            abort(401, 'Invalid credentials.');
        }

        return $this->createTokenSet($request, $user);
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
        $tokenName = 'access_token';

        $accessToken = $request->user()->createToken(
            name: 'access_token',
            abilities: [TokenAbility::ACCESS_API->value, TokenAbility::ACCESS_BROADCASTING->value],
            expiresAt: Carbon::now()->addMinutes(config('sanctum.access_token_expiration')),
            device: $device,
        );

        return response([
           'accessToken' => new NewAccessTokenResource($accessToken),
        ]);
    }

    /**
     * Get a stream token
     *
     * Needs refresh token with ability "issue-access-token"
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
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

        return response()->json([
           'streamToken' => new NewAccessTokenResource($streamToken),
        ]);
    }

    /**
     * Register
     * @unauthenticated
     *
     * @response array{
     *   accessToken: string,
     *   refreshToken: string
     * }
     */
    #[Post('register', 'auth.register')]
    public function register(RegisterRequest $request)
    {
        $user = User::forceCreate([
            'name'     => $request->input('name'),
            'email'    => $request->input('email'),
            'password' => Hash::make($request->input('password')),
        ]);

        return $this->createTokenSet($request, $user);
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


        (new AnonymousNotifiable())->route('mail', $user->email)->notify(new ForgotPasswordNotification($url));

        return response()->json(['message' => __('Reset password link sent to your email.')]);
    }

    /**
     * Reset password
     * @unauthenticated
     */
    #[Post('resetPassword', 'auth.resetPassword')]
    public function resetPassword(ResetPasswordRequest $request)
    {
        $user = User::whereEmail($request->only('email'))->firstOrFail();

        if (!Password::getRepository()->exists($user, $request->input('token'))) {
            abort(400, 'Provided invalid token.');
        }

        $user->password = Hash::make($request->input('password'));
        $user->saveOrFail();

        Password::deleteToken($user);

        return response()->json(['message' => 'Password reset successfully.']);
    }

    /**
     * Verify email
     * @unauthenticated
     */
    #[Post('verify/:id/:hash')]
    public function verify(int $id, string $hash)
    {
        $user = User::query()->findOrFail($id);

        if (method_exists($user, 'createToken') && !hash_equals((string)$hash, sha1($user->getEmailForVerification()))) {
            throw new \Exception('Invalid hash');
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
            Queue::push(new RevokeTokenJob($accessToken));
        }

        $refreshToken = $request->get('refreshToken');
        if ($refreshToken) {
            Queue::push(new RevokeTokenJob($refreshToken));
        }

        return response(null, Response::HTTP_NO_CONTENT);
    }
}