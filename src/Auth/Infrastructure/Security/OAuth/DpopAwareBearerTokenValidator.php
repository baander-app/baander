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
        $authorization = $request->getHeaderLine('Authorization');
        $jwt = trim((string) preg_replace('/^\s*Bearer\s/i', '', $authorization));
        $claims = $this->parseJwtClaims($jwt);

        // Reject malformed/missing audiences before the parent indexes aud[0].
        // These unverified claims are used only to reject: acceptance still
        // requires the parent's signature, expiry, and revocation validation.
        if ($this->resourceServerUri !== null) {
            $audience = $claims['aud'] ?? null;
            $audiences = is_string($audience) ? [$audience] : $audience;
            if (!is_array($audiences) || !array_is_list($audiences)
                || array_filter($audiences, static fn (mixed $value): bool => !is_string($value)) !== []
                || !in_array($this->resourceServerUri, $audiences, true)) {
                throw OAuthServerException::accessDenied('Access token audience does not match resource server identifier');
            }
        }

        $validatedRequest = parent::validateAuthorization($request);

        if (isset($claims['client_id'])) {
            if (!is_string($claims['client_id']) || $claims['client_id'] === '') {
                throw OAuthServerException::accessDenied('Access token client identifier is invalid');
            }
            $validatedRequest = $validatedRequest
                ->withAttribute('oauth_client_id', $claims['client_id']);
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
