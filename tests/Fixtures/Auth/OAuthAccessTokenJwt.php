<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\TokenMetadataEntity;
use Doctrine\ORM\EntityManagerInterface;

/** Signs disposable access-token rows for production firewall acceptance tests. */
final class OAuthAccessTokenJwt
{
    /**
     * Signs the claims the row implies, with `$claims` merged over them.
     *
     * An override replaces the derived claim verbatim, so `aud` can become a list or an object and a
     * claim can carry any JSON shape; a null override removes the claim. `exp` is the row's expiry,
     * so an expired token is a row whose expiry lies in the past and the JWT and the row agree.
     *
     * @param array<string, mixed> $claims
     */
    public static function sign(string $privateKey, AccessTokenEntity $token, string $audience = 'https://baander.app', array $claims = []): string
    {
        $subject = $token->getUserIdentifier();
        $thumbprint = $token->getDpopJkt();
        if ($subject === null || $thumbprint === null) {
            throw new \InvalidArgumentException('Acceptance tokens must have a user and a DPoP key.');
        }

        $claims = array_filter([
            'jti' => $token->getTokenId(),
            'sub' => $subject,
            'aud' => $audience,
            'client_id' => $token->getClient()->getIdentifier(),
            'scopes' => $token->getScopeIdentifiers() ?? [],
            'iat' => time(),
            'nbf' => time() - 1,
            'exp' => $token->getExpiryDateTime()->getTimestamp(),
            'cnf' => ['jkt' => $thumbprint],
            ...$claims,
        ], static fn (mixed $value): bool => $value !== null);
        $body = SignedDpopProof::encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)) . '.'
            . SignedDpopProof::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        if (!openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign disposable access token.');
        }

        return $body . '.' . SignedDpopProof::encode($signature);
    }

    /**
     * Persists the metadata row that binds the token to a client fingerprint.
     *
     * The authenticator compares the stored fingerprint with the `X-Baander-Client-Fingerprint`
     * request header and rejects the token on a mismatch.
     */
    public static function bindFingerprint(EntityManagerInterface $manager, AccessTokenEntity $token, string $fingerprint): TokenMetadataEntity
    {
        $metadata = new TokenMetadataEntity($token, clientFingerprint: $fingerprint);
        $manager->persist($metadata);
        $manager->flush();

        return $metadata;
    }
}
