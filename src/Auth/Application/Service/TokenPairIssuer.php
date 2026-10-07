<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mints the DPoP-bound token pair that starts a new refresh rotation chain.
 *
 * Password login, passkey login, the authorization code grant and the device
 * code grant all issue through here, so every user token carries the same
 * binding: the access token's `cnf.jkt` and the stored proof key that refresh
 * requires (RFC 9449), and the client fingerprint when the token request sent
 * X-Baander-Client-Fingerprint. Refresh rotates the pair in RefreshTokenHandler
 * and carries both bindings forward.
 */
final class TokenPairIssuer
{
    private readonly DateInterval $accessTokenTtl;
    private readonly DateInterval $refreshTokenTtl;

    public function __construct(
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly ScopeAllowlist $scopeAllowlist,
        private readonly EntityManagerInterface $entityManager,
        private readonly JwtGeneratorInterface $jwtGenerator,
        private readonly TokenMetadataRepositoryInterface $tokenMetadataRepository,
        int $accessTokenTtl,
        int $refreshTokenTtl,
    ) {
        $this->accessTokenTtl = new DateInterval(sprintf('PT%dS', $accessTokenTtl));
        $this->refreshTokenTtl = new DateInterval(sprintf('PT%dS', $refreshTokenTtl));
    }

    /**
     * @param string[] $requestedScopes Filtered against the allowlist; none requested yields the default scopes
     * @param (callable(): void)|null $persistWithTokens Further writes that commit or roll back with the tokens
     */
    public function issue(
        Client $client,
        User $user,
        array $requestedScopes,
        string $dpopJkt,
        ?string $tokenName = null,
        ?string $clientFingerprint = null,
        ?string $userAgent = null,
        ?string $ipAddress = null,
        ?callable $persistWithTokens = null,
    ): TokenResponseDTO {
        $chainId = ChainId::generate();

        // Persist the proof key with the token so refresh can require the same key.
        $accessToken = AccessToken::issue(
            $client,
            $user,
            $this->resolveScopes($requestedScopes),
            $tokenName,
            $this->accessTokenTtl,
            $chainId,
            $dpopJkt,
        );

        $refreshToken = RefreshToken::issue(
            $accessToken,
            $chainId,
            $this->refreshTokenTtl,
        );

        $this->entityManager->getConnection()->transactional(function () use ($accessToken, $refreshToken, $persistWithTokens): void {
            if ($persistWithTokens !== null) {
                $persistWithTokens();
            }
            $this->accessTokenRepository->save($accessToken, false);
            $this->refreshTokenRepository->save($refreshToken, false);
            $this->entityManager->flush();
        });

        $this->tokenMetadataRepository->save(TokenMetadata::create(
            tokenId: $accessToken->getId(),
            userAgent: $userAgent,
            clientFingerprint: $clientFingerprint === '' ? null : $clientFingerprint,
            ipAddress: $ipAddress,
        ));

        return new TokenResponseDTO(
            accessToken: $this->jwtGenerator->generate($accessToken, $dpopJkt),
            expiresIn: $this->accessTokenTtl->s,
            refreshToken: $refreshToken->getTokenId()->toString(),
            scopes: $accessToken->getScopeIdentifiers(),
        );
    }

    /**
     * Requested scopes outside the allowlist are dropped; no request yields the default scopes.
     *
     * @param string[] $requestedScopes
     * @return Scope[]
     */
    private function resolveScopes(array $requestedScopes): array
    {
        if ($requestedScopes === []) {
            return Scope::defaultScopes();
        }

        return array_map(
            static fn (string $scope): Scope => new Scope($scope),
            array_values(array_unique($this->scopeAllowlist->filter($requestedScopes))),
        );
    }
}
