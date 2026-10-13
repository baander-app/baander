<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RevokeTokenCommand;
use App\Auth\Domain\Event\OAuth\TokenRevoked;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerNotRunningException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for revoking OAuth 2.0 tokens.
 *
 * Supports single token revocation and full chain revocation. A chain revocation
 * ends the session: after the revocation committed, the web server closes every
 * WebSocket connection that authenticated with one of the chain's access tokens.
 * Closing them earlier would let a reconnect pass on a token the commit has not yet
 * revoked. A token without a chain is revoked on its own.
 *
 * Per RFC 7009, the server responds with 200 OK even if the token does not exist
 * or is already revoked; a token the command's owner does not hold is treated the
 * same way, so the revocation endpoint is no liveness oracle for foreign tokens.
 */
final class RevokeTokenHandler
{
    public function __construct(
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LiveConnectionsPortInterface $liveConnections,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ServerControlException when the chain is revoked but the web server did not close its connections
     */
    #[AsMessageHandler]
    public function __invoke(RevokeTokenCommand $command): void
    {
        $tokenId = TokenId::fromString($command->getTokenId());

        $refreshToken = $this->refreshTokenRepository->findByTokenId($tokenId);
        $accessToken = $refreshToken === null ? $this->accessTokenRepository->findByTokenId($tokenId) : null;
        $token = $refreshToken ?? $accessToken;

        // RFC 7009: an unknown token, and a token of another user, are answered alike.
        if ($token === null || !$this->isHeldBy($token, $command->getOwnerUserId())) {
            return;
        }

        $chainId = $token->getChainId();
        if ($command->shouldRevokeChain() && $chainId !== null) {
            $this->revokeChain($chainId);

            $this->eventDispatcher->dispatch(new TokenRevoked(
                tokenId: $tokenId->toString(),
                tokenType: 'access_token',
            ));

            return;
        }

        if ($refreshToken !== null) {
            $refreshToken->revoke();
            $this->entityManager->getConnection()->transactional(function () use ($refreshToken): void {
                $this->refreshTokenRepository->save($refreshToken, false);
                $this->entityManager->flush();
            });

            $this->eventDispatcher->dispatch(new TokenRevoked(
                tokenId: $tokenId->toString(),
                tokenType: 'refresh_token',
            ));

            return;
        }

        assert($accessToken !== null);
        $accessToken->revoke();
        $this->entityManager->getConnection()->transactional(function () use ($accessToken): void {
            $this->accessTokenRepository->save($accessToken, false);
            $this->entityManager->flush();
        });

        $this->eventDispatcher->dispatch(new TokenRevoked(
            tokenId: $tokenId->toString(),
            tokenType: 'access_token',
        ));
    }

    private function isHeldBy(AccessToken|RefreshToken $token, ?string $ownerUserId): bool
    {
        if ($ownerUserId === null) {
            return true;
        }

        $accessToken = $token instanceof RefreshToken ? $token->getAccessToken() : $token;
        $holder = $accessToken->getUser()?->getId();

        return $holder !== null && $holder->toString() === $ownerUserId;
    }

    private function revokeChain(ChainId $chainId): void
    {
        $accessTokenIds = $this->entityManager->getConnection()->transactional(function () use ($chainId): array {
            // Lock order of every chain revocation: refresh rows before access rows. A
            // refresh of the same chain locks its refresh row first, so the two never
            // wait on each other in opposite order.
            $this->refreshTokenRepository->revokeByChainId($chainId);

            // The ids are listed under the refresh-row locks, before the access rows are
            // revoked: a rotation that committed a replacement pair while this revocation
            // waited is in the list, so its socket is closed too.
            $accessTokenIds = $this->accessTokenRepository->findTokenIdsByChainId($chainId);
            $this->accessTokenRepository->revokeByChainId($chainId);

            return $accessTokenIds;
        });

        $this->closeLiveConnections($accessTokenIds, $chainId);
    }

    /** @param list<string> $accessTokenIds */
    private function closeLiveConnections(array $accessTokenIds, ChainId $chainId): void
    {
        try {
            $this->liveConnections->closeForAccessTokens($accessTokenIds);
        } catch (ServerNotRunningException) {
            // Only reached outside the web server: a console command in a container
            // without one, so the server's connections are not reachable from here.
            $this->logger->warning(
                'No web server runs in this container, so the open WebSocket connections of token chain "{chain}" were not closed.',
                ['chain' => $chainId->toString()],
            );
        }
    }
}
