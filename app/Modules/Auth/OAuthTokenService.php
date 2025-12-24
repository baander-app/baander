<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Models\OAuth\Client;
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
    public function __construct(
        private readonly AuthorizationServer $authorizationServer,
        private readonly TokenBindingService $tokenBindingService,
        private readonly GeoLocationService  $geoLocationService,
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

        $tokenJti = $this->extractJtiFromToken($responseBody['access_token']);
        $accessToken = Token::where('token_id', $tokenJti)->first();

        if (!$accessToken) {
            throw new \RuntimeException('Failed to create token set token');
        }

        $this->createTokenMetadata($request, $accessToken, $sessionId, $fingerprint);

        return $responseBody;
    }

    /**
     * Refresh an access token
     */
    public function refreshToken(Request $request, string $refreshToken): array
    {
        $client = Client::where('first_party', true)->firstOrFail();

        $psrRequest = $this->psr->createRequestWithBody($request, [
            'grant_type'    => 'refresh_token',
            'client_id'     => $client->public_id,
            'client_secret' => $client->secret,
            'refresh_token' => $refreshToken,
            'scope'         => 'access-api access-broadcasting',
        ]);

        $psrResponse = $this->psr->createResponse();
        $response = $this->authorizationServer->respondToAccessTokenRequest($psrRequest, $psrResponse);

        return json_decode((string)$response->getBody(), true);
    }

    /**
     * Extract JTI (JWT ID) from a JWT token
     */
    private function extractJtiFromToken(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWT format');
        }

        $payload = json_decode(base64_decode($parts[1]), true);
        if (!isset($payload['jti'])) {
            throw new \RuntimeException('JWT missing JTI claim');
        }

        return $payload['jti'];
    }

    /**
     * Create token metadata for security bindings
     */
    private function createTokenMetadata(
        Request                 $request,
        Token $token,
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
