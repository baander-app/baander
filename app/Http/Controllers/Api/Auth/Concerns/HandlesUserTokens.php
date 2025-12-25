<?php

namespace App\Http\Controllers\Api\Auth\Concerns;

use App\Modules\Auth\OAuth\Services\OAuthTokenService;
use App\Modules\Auth\TokenBindingService;
use App\Models\User;
use Illuminate\Http\{JsonResponse, Request};

trait HandlesUserTokens
{
    /**
     * Create a token set for a user after successful authentication.
     *
     * Generates OAuth access and refresh tokens with security bindings
     * for device fingerprinting, IP tracking, and location data.
     *
     * @param Request $request The authentication request
     * @param User $user The authenticated user
     * @response array{
     *   tokenType: string,
     *   expires_in: int,
     *   access_token: string,
     *   refresh_token: string
     * }
     */
    protected function createTokenSet(Request $request, User $user): JsonResponse
    {
        /** @var OAuthTokenService $oauthTokenService */
        $oauthTokenService = app(OAuthTokenService::class);

        /** @var TokenBindingService $tokenBindingService */
        $tokenBindingService = app(TokenBindingService::class);

        // Generate session and fingerprint for security bindings
        $sessionId = $tokenBindingService->generateSessionId();
        $fingerprint = $tokenBindingService->generateClientFingerprint($request);

        // Create OAuth tokens with metadata
        $tokens = $oauthTokenService->createTokenSet(
            $request,
            $user,
            ['access-api', 'access-broadcasting'],
            $sessionId,
            $fingerprint,
        );

        return response()->json([
            'accessToken' => $tokens['access_token'],
            'refreshToken' => $tokens['refresh_token'],
            'sessionId' => $sessionId,
        ], 201);
    }
}
