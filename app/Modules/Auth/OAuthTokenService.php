<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Models\Auth\OAuth\Client;
use App\Models\Auth\OAuth\RefreshToken;
use App\Models\Auth\OAuth\Token;
use App\Models\Auth\OAuth\TokenMetadata;
use App\Models\User;
use App\Modules\Auth\OAuth\Psr7Factory;
use Defuse\Crypto\Crypto;
use Illuminate\Http\Request;
use League\OAuth2\Server\AuthorizationServer;

/**
 * Service for creating OAuth tokens for first-party authentication
 */
class OAuthTokenService
{
    public function __construct(
        private readonly AuthorizationServer $authorizationServer,
        private readonly GeoLocationService  $geoLocationService,
        private readonly TokenChainService  $tokenChainService,
        private readonly Psr7Factory         $psr,
    )
    {
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

        // Extract JTI from access token (JWT)
        $accessTokenJti = $this->extractJtiFromToken($responseBody['access_token']);
        $accessToken = Token::where('token_id', $accessTokenJti)->first();

        if (!$accessToken) {
            throw new \RuntimeException('Failed to retrieve newly created access token');
        }

        // Get the refresh token and store by extracting JTI from decrypted value
        $refreshToken = null;
        if (isset($responseBody['refresh_token'])) {
            $refreshTokenJti = $this->extractJtiFromEncryptedToken($responseBody['refresh_token']);
            $refreshToken = RefreshToken::where('token_id', $refreshTokenJti)->first();
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
        $refreshTokenJti = $this->extractJtiFromEncryptedToken($refreshTokenString);
        $previousRefreshToken = RefreshToken::where('token_id', $refreshTokenJti)->first();

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

        // Get new access token by extracting JTI from JWT
        $newAccessTokenJti = $this->extractJtiFromToken($responseBody['access_token']);
        $newAccessToken = Token::where('token_id', $newAccessTokenJti)->first();

        // Get new refresh token by extracting JTI from decrypted value
        $newRefreshTokenJti = $this->extractJtiFromEncryptedToken($responseBody['refresh_token']);
        $newRefreshToken = RefreshToken::where('token_id', $newRefreshTokenJti)->first();

        if (!$newAccessToken || !$newRefreshToken) {
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
     * Extract JTI (JWT ID) from a JWT token
     */
    private function extractJtiFromToken(string $token): string
    {
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
     * Extract JTI from an encrypted refresh token
     */
    private function extractJtiFromEncryptedToken(string $encryptedToken): string
    {
        $encryptionKey = config('oauth.encryption_key');

        try {
            $decrypted = Crypto::decryptWithPassword($encryptedToken, $encryptionKey);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to decrypt refresh token: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode($decrypted, true);

        if (!isset($data['refresh_token_id'])) {
            throw new \RuntimeException('Decrypted token missing refresh_token_id');
        }

        return $data['refresh_token_id'];
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
