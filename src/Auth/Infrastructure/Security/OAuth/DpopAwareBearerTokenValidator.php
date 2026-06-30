<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use DateInterval;
use League\OAuth2\Server\AuthorizationValidators\BearerTokenValidator;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Extends BearerTokenValidator to read client_id from a dedicated JWT claim
 * and validate the aud claim against the configured resource server identifier.
 */
final class DpopAwareBearerTokenValidator extends BearerTokenValidator
{
    public function __construct(
        AccessTokenRepositoryInterface $accessTokenRepository,
        ?DateInterval $jwtValidAtDateLeeway = null,
        private readonly ?string $resourceServerUri = null,
    ) {
        parent::__construct($accessTokenRepository, $jwtValidAtDateLeeway);
    }

    public function validateAuthorization(ServerRequestInterface $request): ServerRequestInterface
    {
        $validatedRequest = parent::validateAuthorization($request);

        $authorization = $validatedRequest->getAttribute('Authorization', '');
        if (!is_string($authorization)) {
            $authorization = '';
        }

        // Extract JWT claims by parsing the token from the Authorization header
        $jwt = trim((string) preg_replace('/^\s*Bearer\s/i', '', $authorization));
        if ($jwt === '') {
            return $validatedRequest;
        }

        // Read the aud and client_id claims via reflection on the parent's
        // validated request attributes — parent already sets oauth_client_id
        // from aud[0], we need to override it from the client_id claim.
        //
        // Instead of re-parsing the JWT, we use a simple approach: the parent
        // sets oauth_client_id from aud[0]. We need to get the actual client_id
        // from the JWT. Since the parent already validates the token, we can
        // safely decode it here to read the client_id claim.
        $claims = $this->parseJwtClaims($jwt);

        if (isset($claims['client_id'])) {
            $validatedRequest = $validatedRequest
                ->withAttribute('oauth_client_id', $claims['client_id']);
        }

        // Validate aud claim against resource server identifier
        if ($this->resourceServerUri !== null && isset($claims['aud'])) {
            if ($claims['aud'] !== $this->resourceServerUri) {
                throw OAuthServerException::accessDenied('Access token audience does not match resource server identifier');
            }
        }

        return $validatedRequest;
    }

    /**
     * Parse JWT claims without full validation (parent already validated).
     *
     * @return array<string, mixed>
     */
    private function parseJwtClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            return [];
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}
