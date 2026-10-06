<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Issues first-party token pairs after password or passkey login.
 *
 * The Symfony authenticators verify the user's credentials; this handler mints a
 * DPoP-bound access token and a refresh token that starts a new rotation chain.
 */
final class IssueTokenHandler
{
    private readonly DateInterval $accessTokenTtl;
    private readonly DateInterval $refreshTokenTtl;

    public function __construct(
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly UserRepositoryInterface $userRepository,
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

    #[AsMessageHandler]
    public function __invoke(IssueTokenCommand $command): TokenResponseDTO
    {
        $client = $this->clientRepository->findClientByUuid($command->getClientId());
        if ($client === null) {
            throw new RuntimeException('Client not found.');
        }
        if ($client->isRevoked()) {
            throw new RuntimeException('Client has been revoked.');
        }

        $user = $this->userRepository->findByUuid($command->getUserId());
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        $scopes = $this->resolveScopes($command->getScopes());
        $chainId = ChainId::generate();

        // Persist the proof key with the token so refresh can require the same key.
        $accessToken = AccessToken::issue(
            $client,
            $user,
            $scopes,
            $command->getTokenName(),
            $this->accessTokenTtl,
            $chainId,
            $command->getDpopJkt(),
        );

        $refreshToken = RefreshToken::issue(
            $accessToken,
            $chainId,
            $this->refreshTokenTtl,
        );

        $this->entityManager->getConnection()->transactional(function () use ($accessToken, $refreshToken): void {
            $this->accessTokenRepository->save($accessToken, false);
            $this->refreshTokenRepository->save($refreshToken, false);
            $this->entityManager->flush();
        });

        $fingerprint = $command->getClientFingerprint();
        $this->tokenMetadataRepository->save(TokenMetadata::create(
            tokenId: $accessToken->getId(),
            userAgent: $command->getUserAgent(),
            clientFingerprint: $fingerprint === '' ? null : $fingerprint,
            ipAddress: $command->getIpAddress(),
        ));

        return new TokenResponseDTO(
            accessToken: $this->jwtGenerator->generate($accessToken, $command->getDpopJkt()),
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
