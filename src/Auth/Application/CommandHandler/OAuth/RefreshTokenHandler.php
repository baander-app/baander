<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for the Refresh Token grant (token rotation).
 *
 * Validates the refresh token, checks chain integrity for replay attacks,
 * marks the old token as used, and returns a new access/refresh token pair.
 */
final class RefreshTokenHandler
{
    private readonly DateInterval $accessTokenTtl;
    private readonly DateInterval $refreshTokenTtl;

    public function __construct(
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly TokenChainValidator $chainValidator,
        private readonly EntityManagerInterface $entityManager,
        private readonly JwtGeneratorInterface $jwtGenerator,
        int $accessTokenTtl,
        int $refreshTokenTtl,
    ) {
        $this->accessTokenTtl = new DateInterval(sprintf('PT%dS', $accessTokenTtl));
        $this->refreshTokenTtl = new DateInterval(sprintf('PT%dS', $refreshTokenTtl));
    }

    #[AsMessageHandler]
    public function __invoke(RefreshTokenCommand $command): TokenResponseDTO
    {
        $refreshTokenId = TokenId::fromString($command->getRefreshTokenId());
        $refreshToken = $this->refreshTokenRepository->findByTokenId($refreshTokenId);

        if ($refreshToken === null) {
            throw new RuntimeException('Refresh token not found.');
        }

        if ($refreshToken->isRevoked()) {
            throw new RuntimeException('Refresh token has been revoked.');
        }

        if ($refreshToken->isExpired()) {
            throw new RuntimeException('Refresh token has expired.');
        }

        // Validate chain integrity (replay detection).
        // If the token was already used, this will revoke the entire chain.
        // Tokens without a chainId are outside the rotation model and skip validation.
        if ($refreshToken->getChainId() !== null) {
            $this->chainValidator->validateWithLoadedPrevious($refreshToken);
        }

        try {
            return $this->entityManager->getConnection()->transactional(function () use ($refreshTokenId, $command): TokenResponseDTO {
                // Atomically consume the refresh token. If another request already used
                // this token, the conditional UPDATE will affect zero rows.
                $consumedToken = $this->refreshTokenRepository->consumeByTokenId($refreshTokenId);
                if ($consumedToken === null) {
                    throw new RuntimeException('Refresh token reuse detected.');
                }

                $refreshToken = $consumedToken;
                $oldAccessToken = $refreshToken->getAccessToken();

                // Issue new token pair in the same chain
                $chainId = $refreshToken->getChainId();
                $client = $oldAccessToken->getClient();
                $user = $oldAccessToken->getUser();

                $newAccessToken = AccessToken::issue(
                    $client,
                    $user,
                    $oldAccessToken->getScopes(),
                    $oldAccessToken->getName(),
                    $this->accessTokenTtl,
                    $chainId,
                );

                $newRefreshToken = RefreshToken::issue(
                    $newAccessToken,
                    $chainId,
                    $this->refreshTokenTtl,
                    $refreshToken, // Chain link to previous
                );

                $this->refreshTokenRepository->save($refreshToken, false);

                // Revoke the old access token
                $oldAccessToken->revoke();
                $this->accessTokenRepository->save($oldAccessToken, false);

                $this->accessTokenRepository->save($newAccessToken, false);
                $this->refreshTokenRepository->save($newRefreshToken, false);
                $this->entityManager->flush();

                return new TokenResponseDTO(
                    accessToken: $this->jwtGenerator->generate($newAccessToken, $command->getDpopJkt()),
                    expiresIn: $this->accessTokenTtl->s,
                    refreshToken: $newRefreshToken->getTokenId()->toString(),
                    scopes: $newAccessToken->getScopeIdentifiers(),
                );
            });
        } catch (\Throwable $exception) {
            // The rollback restores rows; discard entities carrying rolled-back state.
            $this->entityManager->clear();
            throw $exception;
        }
    }
}
