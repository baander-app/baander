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

/**
 * Generates RS256-signed JWTs compatible with league/oauth2-server's BearerTokenValidator.
 *
 * The JWT claims mirror League's AccessTokenTrait::convertToJWT() so that the
 * ResourceServer can validate tokens issued by our custom IssueTokenHandler.
 */
final class JwtGenerator implements JwtGeneratorInterface
{
    private Configuration $jwtConfiguration;

    public function __construct(string $privateKeyPath, private readonly string $resourceServerUri)
    {
        $cryptKey = new CryptKey($privateKeyPath);

        $this->jwtConfiguration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($cryptKey->getKeyContents(), $cryptKey->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty'),
        );
    }

    public function generate(AccessToken $accessToken, ?string $dpopJkt = null): string
    {
        $client = $accessToken->getClient();
        $user = $accessToken->getUser();

        // Mirror League's getSubjectIdentifier(): user UUID if present, else client UUID
        $subject = $user !== null
            ? $user->getId()->toString()
            : $client->getId()->toString();

        $builder = $this->jwtConfiguration->builder()
            ->permittedFor($this->resourceServerUri)
            ->identifiedBy($accessToken->getTokenId()->toString())
            ->issuedAt(new DateTimeImmutable())
            ->canOnlyBeUsedAfter(new DateTimeImmutable())
            ->expiresAt($accessToken->getExpiresAt() ?? new DateTimeImmutable('+1 hour'))
            ->relatedTo($subject)
            ->withClaim('scopes', $accessToken->getScopeIdentifiers())
            ->withClaim('client_id', $client->getId()->toString());

        if ($dpopJkt !== null) {
            $builder = $builder->withClaim('cnf', ['jkt' => $dpopJkt]);
        }

        return $builder
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey())
            ->toString();
    }
}
