<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Domain\Model\OAuth\AccessToken;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKey;
use RuntimeException;

/**
 * Generates RS256-signed JWT access tokens (RFC 9068) compatible with league/oauth2-server's BearerTokenValidator.
 *
 * The claims mirror League's AccessTokenTrait::convertToJWT() so that the ResourceServer
 * can validate them. The header carries the `at+jwt` type and the key's RFC 7638
 * thumbprint as `kid`, which the JWKS publishes too. Baander is both the issuer and
 * the audience of its tokens, so `iss` and `aud` are the same identifier.
 */
final class JwtGenerator implements JwtGeneratorInterface
{
    private const string ACCESS_TOKEN_TYPE = 'at+jwt';

    private Configuration $jwtConfiguration;
    private string $keyId;

    /** @param string $resourceServerUri The issuer identifier, which is also the audience */
    public function __construct(string $privateKeyPath, private readonly string $resourceServerUri)
    {
        $cryptKey = new CryptKey($privateKeyPath);
        $passPhrase = $cryptKey->getPassPhrase() ?? '';

        $this->jwtConfiguration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($cryptKey->getKeyContents(), $passPhrase),
            InMemory::plainText('empty', 'empty'),
        );
        $privateKey = openssl_pkey_get_private($cryptKey->getKeyContents(), $passPhrase)
            ?: throw new RuntimeException('Failed to parse the access token signing key.');
        $this->keyId = AccessTokenSigningKey::jwkOf($privateKey)['kid'];
    }

    public function generate(AccessToken $accessToken, ?string $dpopJkt = null): string
    {
        $client = $accessToken->getClient();
        $user = $accessToken->getUser();

        // The subject is the public user identity, or the public client identity for a client-only token.
        $subject = $user !== null
            ? $user->getPublicId()->toString()
            : $client->getPublicId()->toString();

        $builder = $this->jwtConfiguration->builder()
            ->withHeader('typ', self::ACCESS_TOKEN_TYPE)
            ->withHeader('kid', $this->keyId)
            ->issuedBy($this->resourceServerUri)
            ->permittedFor($this->resourceServerUri)
            ->identifiedBy($accessToken->getTokenId()->toString())
            ->issuedAt(new DateTimeImmutable())
            ->canOnlyBeUsedAfter(new DateTimeImmutable())
            ->expiresAt($accessToken->getExpiresAt() ?? new DateTimeImmutable('+1 hour'))
            ->relatedTo($subject)
            ->withClaim('scopes', $accessToken->getScopeIdentifiers())
            ->withClaim('client_id', $client->getPublicId()->toString());

        if ($dpopJkt !== null) {
            $builder = $builder->withClaim('cnf', ['jkt' => $dpopJkt]);
        }

        return $builder
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey())
            ->toString();
    }
}
