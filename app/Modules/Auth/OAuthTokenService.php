<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Models\OAuth\Client;
use App\Models\OAuth\RefreshToken;
use App\Models\OAuth\Token;
use App\Models\TokenMetadata;
use App\Models\User;
use App\Modules\OAuth\Psr7Factory;
use Illuminate\Http\Request;
use League\OAuth2\Server\AuthorizationServer;

/**
 * Service for creating OAuth tokens for first-party authentication
 */
class OAuthTokenService
{
    private string $encryptionKey;

    public function __construct(
        private readonly AuthorizationServer $authorizationServer,
        private readonly GeoLocationService  $geoLocationService,
        private readonly TokenChainService  $tokenChainService,
        private readonly Psr7Factory         $psr,
    )
    {
        $this->encryptionKey = config('oauth.encryption_key');
    }

    /**
     * Create access and refresh tokens for an already-authenticated user
     *
     * @param Request $request The current HTTP request
     * @param User $user The user to create tokens for
     * @param array $scopes Scopes to grant
     * @param string $sessionId Session ID for binding
     * @param string $fingerprint Client fingerprint for binding
     * @return array{
     *     access_token: string,
     *     expires_in: int,
     *     refresh_token: string|null
     * }
     */
    public function createTokenSet(
        Request $request,
        User    $user,
        array   $scopes,
        string  $sessionId,
        string  $fingerprint,
    ): array
    {
        $client = Client::where('first_party', true)->firstOrFail();

        $psrRequest = $this->psr->createRequestWithBody($request, [
            'grant_type'    => 'pre_authenticated',
            'client_id'     => $client->public_id,
            'client_secret' => $client->secret,
            'user_id'       => (string)$user->id,
            'scope'         => implode(' ', $scopes),
        ]);

        $psrResponse = $this->psr->createResponse();
        $response = $this->authorizationServer->respondToAccessTokenRequest($psrRequest, $psrResponse);
        $responseBody = json_decode((string)$response->getBody(), true);

        // Get the access token that was just created (time-bounded query)
        $accessToken = Token::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subSeconds(5))
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$accessToken) {
            throw new \RuntimeException('Failed to retrieve newly created access token');
        }

        // Get the refresh token (if any) and update with encrypted token string
        $refreshToken = null;
        if (isset($responseBody['refresh_token'])) {
            $refreshToken = RefreshToken::where('access_token_id', $accessToken->id)
                ->orderBy('created_at', 'desc')
                ->first();

            $refreshToken?->update(['encrypted_token' => $responseBody['refresh_token']]);
        }

        // Link tokens in a new chain
        if ($refreshToken) {
            $this->tokenChainService->linkTokens($accessToken, $refreshToken);
        }

        $this->createTokenMetadata($request, $accessToken, $sessionId, $fingerprint);

        return $responseBody;
    }

    /**
     * Refresh an access token using refresh token with rotation
     *
     * @param Request $request The current HTTP request
     * @param string $refreshTokenString The refresh token string
     * @return array{
     *     access_token: string,
     *     expires_in: int,
     *     refresh_token: string
     * }
     * @throws \RuntimeException If refresh token has been reused or revoked
     */
    public function refreshToken(Request $request, string $refreshTokenString): array
    {
        $previousRefreshToken = RefreshToken::where('encrypted_token', $refreshTokenString)->first();

        if (!$previousRefreshToken) {
            throw new \RuntimeException('Refresh token not found');
        }

        // Validate refresh token and check for reuse
        $this->tokenChainService->validateRefreshToken($previousRefreshToken);

        $client = Client::whereFirstParty()->firstOrFail();

        $psrRequest = $this->psr->createRequestWithBody($request, [
            'grant_type'    => 'refresh_token',
            'client_id'     => $client->public_id,
            'client_secret' => $client->secret,
            'refresh_token' => $refreshTokenString,
            'scope'         => 'access-api access-broadcasting',
        ]);

        $psrResponse = $this->psr->createResponse();
        $response = $this->authorizationServer->respondToAccessTokenRequest($psrRequest, $psrResponse);
        $responseBody = json_decode((string)$response->getBody(), true);

        // Get new access token (time-bounded query)
        $user = $request->user();
        $newAccessToken = Token::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subSeconds(5))
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$newAccessToken) {
            throw new \RuntimeException('Failed to create refreshed access token');
        }

        // Get new refresh token and store encrypted string
        $newRefreshToken = RefreshToken::where('access_token_id', $newAccessToken->id)
            ->orderBy('created_at', 'desc')
            ->first();

        $newRefreshToken?->update(['encrypted_token' => $responseBody['refresh_token']]);

        if (!$newRefreshToken) {
            throw new \RuntimeException('Failed to create refreshed tokens');
        }

        // Link new tokens to the chain, marking the previous refresh token as used
        $this->tokenChainService->linkTokens(
            $newAccessToken,
            $newRefreshToken,
            $previousRefreshToken->id,
        );

        // Update the access token's last refreshed timestamp
        $newAccessToken->update(['last_refreshed_at' => now()]);

        return $responseBody;
    }

    /**
     * Extract JTI (JWT ID) from a JWT token or encrypted token
     */
    private function extractJtiFromToken(string $token): string
    {
        // Check if it's an encrypted token (starts with specific prefix)
        if (str_starts_with($token, 'def')) {
            throw new \RuntimeException('Cannot extract JTI from encrypted tokens');
        }

        // Otherwise, treat as JWT and extract JTI from payload
        $lastDotPos = strrpos($token, '.');
        if ($lastDotPos === false) {
            throw new \RuntimeException('Invalid JWT format: no dots found');
        }

        $secondLastDotPos = strrpos(substr($token, 0, $lastDotPos), '.');
        if ($secondLastDotPos === false) {
            throw new \RuntimeException('Invalid JWT format: less than 2 dots found');
        }

        // Extract the payload
        $payloadEncoded = substr($token, $secondLastDotPos + 1, $lastDotPos - $secondLastDotPos - 1);

        // Add padding if needed
        $payloadPadded = $this->addBase64Padding($payloadEncoded);

        $payload = json_decode($this->base64UrlDecode($payloadPadded), true);

        if (!isset($payload['jti'])) {
            throw new \RuntimeException('JWT missing JTI claim');
        }

        return $payload['jti'];
    }

    /**
     * Add base64 padding if missing
     */
    private function addBase64Padding(string $data): string
    {
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return $data;
    }

    /**
     * Base64 URL-safe decoding
     */
    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Create token metadata for security bindings
     */
    private function createTokenMetadata(
        Request                 $request,
        Token                   $token,
        string                  $sessionId,
        string                  $fingerprint,
    ): void
    {
        $ipAddress = $request->ip();
        $locationData = $this->geoLocationService->getLocationData($ipAddress);

        TokenMetadata::create([
            'token_id'           => $token->token_id,
            'client_fingerprint' => $fingerprint,
            'session_id'         => $sessionId,
            'ip_address'         => $ipAddress,
            'ip_history'         => [[
                                         'ip'        => $ipAddress,
                                         'timestamp' => now()->toISOString(),
                                         'location'  => $locationData,
                                     ]],
            'ip_change_count'    => 0,
            'country_code'       => $locationData['country_code'],
            'city'               => $locationData['city'],
            'user_agent'         => $request->userAgent(),
        ]);
    }
}
