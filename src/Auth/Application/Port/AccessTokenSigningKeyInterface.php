<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

/**
 * The key that signs Baander's access tokens.
 */
interface AccessTokenSigningKeyInterface
{
    /**
     * The public key as a JWK (RFC 7517), with its RFC 7638 thumbprint as `kid`.
     *
     * Access tokens carry the same `kid` in their header.
     *
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}
     */
    public function publicJwk(): array;

    /**
     * The `jti` of a JWT this key signed, expired or not; null for any other string.
     */
    public function tokenIdOf(string $jwt): ?string;
}
