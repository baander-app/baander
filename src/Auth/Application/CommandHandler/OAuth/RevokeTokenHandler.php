<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RevokeTokenCommand;
use App\Auth\Domain\Event\OAuth\TokenRevoked;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for revoking OAuth 2.0 tokens.
 *
 * Supports single token revocation and full chain revocation.
 * Per RFC 7009, the server responds with 200 OK even if the token
 * does not exist or is already revoked.
 */
final class RevokeTokenHandler
{
    public function __construct(
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RevokeTokenCommand $command): void
    {
        $tokenId = TokenId::fromString($command->getTokenId());

        $refreshToken = $this->refreshTokenRepository->findByTokenId($tokenId);

        if ($command->shouldRevokeChain()) {
            if ($refreshToken !== null) {
                $chainId = $refreshToken->getChainId();
                if ($chainId !== null) {
                    $this->entityManager->getConnection()->transactional(function () use ($chainId): void {
                        $this->accessTokenRepository->revokeByChainId($chainId);
                        $this->refreshTokenRepository->revokeByChainId($chainId);
                    });

                    $this->eventDispatcher->dispatch(new TokenRevoked(
                        tokenId: $tokenId->toString(),
                        tokenType: 'access_token',
                    ));
                }

                return;
            }

            $accessToken = $this->accessTokenRepository->findByTokenId($tokenId);

            if ($accessToken !== null && $accessToken->getChainId() !== null) {
                $chainId = $accessToken->getChainId();
                $this->entityManager->getConnection()->transactional(function () use ($chainId): void {
                    $this->accessTokenRepository->revokeByChainId($chainId);
                    $this->refreshTokenRepository->revokeByChainId($chainId);
                });

                $this->eventDispatcher->dispatch(new TokenRevoked(
                    tokenId: $tokenId->toString(),
                    tokenType: 'access_token',
                ));

                return;
            }

            return;
        }

        // Single token revocation

        if ($refreshToken !== null) {
            $refreshToken->revoke();
            $this->entityManager->getConnection()->transactional(function () use ($refreshToken): void {
                $this->refreshTokenRepository->save($refreshToken);
            });

            $this->eventDispatcher->dispatch(new TokenRevoked(
                tokenId: $tokenId->toString(),
                tokenType: 'refresh_token',
            ));

            return;
        }

        $accessToken = $this->accessTokenRepository->findByTokenId($tokenId);

        if ($accessToken !== null) {
            $accessToken->revoke();
            $this->entityManager->getConnection()->transactional(function () use ($accessToken): void {
                $this->accessTokenRepository->save($accessToken);
            });

            $this->eventDispatcher->dispatch(new TokenRevoked(
                tokenId: $tokenId->toString(),
                tokenType: 'access_token',
            ));

            return;
        }

        // RFC 7009: Return 200 OK even if token not found
    }
}
