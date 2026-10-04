<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;

/** Signs disposable access-token rows for production firewall acceptance tests. */
final class OAuthAccessTokenJwt
{
    public static function sign(string $privateKey, AccessTokenEntity $token, string $audience = 'https://baander.app'): string
    {
        $subject = $token->getUserIdentifier();
        $thumbprint = $token->getDpopJkt();
        if ($subject === null || $thumbprint === null) {
            throw new \InvalidArgumentException('Acceptance tokens must have a user and a DPoP key.');
        }

        $claims = [
            'jti' => $token->getTokenId(),
            'sub' => $subject,
            'aud' => $audience,
            'client_id' => $token->getClient()->getIdentifier(),
            'scopes' => $token->getScopeIdentifiers() ?? [],
            'iat' => time(),
            'nbf' => time() - 1,
            'exp' => $token->getExpiryDateTime()->getTimestamp(),
            'cnf' => ['jkt' => $thumbprint],
        ];
        $body = SignedDpopProof::encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)) . '.'
            . SignedDpopProof::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        if (!openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign disposable access token.');
        }

        return $body . '.' . SignedDpopProof::encode($signature);
    }
}
