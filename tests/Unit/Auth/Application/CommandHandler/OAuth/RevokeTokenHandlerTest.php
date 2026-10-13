<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\OAuth\RevokeTokenCommand;
use App\Auth\Application\CommandHandler\OAuth\RevokeTokenHandler;
use App\Shared\Domain\Model\Email;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerNotRunningException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class RevokeTokenHandlerTest extends TestCase
{
    private AccessTokenRepositoryInterface&Stub $accessTokenRepository;
    private RefreshTokenRepositoryInterface&Stub $refreshTokenRepository;
    private EntityManagerInterface&Stub $entityManager;
    private Connection&MockObject $connection;
    private EventDispatcherInterface&Stub $eventDispatcher;
    private LiveConnectionsPortInterface&Stub $liveConnections;
    private RevokeTokenHandler $handler;

    protected function setUp(): void
    {
        $this->accessTokenRepository = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createStub(RefreshTokenRepositoryInterface::class);

        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('transactional')->willReturnCallback(
            static fn (callable $callback): mixed => $callback(),
        );

        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);

        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->liveConnections = $this->createStub(LiveConnectionsPortInterface::class);

        $this->handler = $this->createRevokeTokenHandlerFixture();
    }

    private function createRevokeTokenHandlerFixture(?LoggerInterface $logger = null): RevokeTokenHandler
    {
        return new RevokeTokenHandler(
            $this->accessTokenRepository,
            $this->refreshTokenRepository,
            $this->entityManager,
            $this->eventDispatcher,
            $this->liveConnections,
            $logger ?? new NullLogger(),
        );
    }

    // --- Chain revocation ---

    public function testChainRevocationViaRefreshTokenRevokesRefreshRowsBeforeAccessRowsAndClosesTheChainsSockets(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->liveConnections = $this->createMock(LiveConnectionsPortInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture();

        $chainId = ChainId::generate();
        $first = $this->createAccessToken($chainId);
        $firstRefresh = RefreshToken::issue($first, $chainId);
        $second = $this->createAccessToken($chainId);
        $secondRefresh = RefreshToken::issue($second, $chainId);
        $tokenId = $secondRefresh->getTokenId()->toString();

        $this->refreshTokenRepository
            ->expects($this->once())
            ->method('findByTokenId')
            ->with($this->callback(fn (TokenId $id): bool => $id->toString() === $tokenId))
            ->willReturn($secondRefresh);
        $order = [];
        $this->accessTokenRepository
            ->expects($this->once())
            ->method('findTokenIdsByChainId')
            ->with($this->callback(fn (ChainId $c): bool => $c->equals($chainId)))
            ->willReturnCallback(static function () use (&$order, $first, $second): array {
                $order[] = 'list';

                return [$first->getTokenId()->toString(), $second->getTokenId()->toString()];
            });

        $this->refreshTokenRepository
            ->expects($this->once())
            ->method('revokeByChainId')
            ->with($this->callback(fn (ChainId $c): bool => $c->equals($chainId)))
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'refresh';
            });
        $this->accessTokenRepository
            ->expects($this->once())
            ->method('revokeByChainId')
            ->with($this->callback(fn (ChainId $c): bool => $c->equals($chainId)))
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'access';
            });
        $this->liveConnections
            ->expects($this->once())
            ->method('closeForAccessTokens')
            ->with([$first->getTokenId()->toString(), $second->getTokenId()->toString()])
            ->willReturnCallback(static function () use (&$order): int {
                $order[] = 'close';

                return 2;
            });

        $this->connection
            ->expects($this->once())
            ->method('transactional');

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: true));

        $this->assertSame(['refresh', 'list', 'access', 'close'], $order, 'Refresh rows lock before the access tokens are listed and revoked; sockets close after the commit.');
    }

    public function testChainRevocationViaAccessToken(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->liveConnections = $this->createMock(LiveConnectionsPortInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture();

        $chainId = ChainId::generate();
        $accessToken = $this->createAccessToken($chainId);
        $refreshToken = RefreshToken::issue($accessToken, $chainId);
        $tokenId = $accessToken->getTokenId()->toString();

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('findByTokenId')
            ->with($this->callback(fn (TokenId $id): bool => $id->toString() === $tokenId))
            ->willReturn($accessToken);
        $this->accessTokenRepository
            ->method('findTokenIdsByChainId')
            ->willReturn([$tokenId]);

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('revokeByChainId')
            ->with($this->callback(fn (ChainId $c): bool => $c->equals($chainId)));

        $this->refreshTokenRepository
            ->expects($this->once())
            ->method('revokeByChainId')
            ->with($this->callback(fn (ChainId $c): bool => $c->equals($chainId)));

        $this->liveConnections
            ->expects($this->once())
            ->method('closeForAccessTokens')
            ->with([$tokenId])
            ->willReturn(1);

        $this->connection
            ->expects($this->once())
            ->method('transactional');

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: true));
    }

    public function testChainRevocationReturnsSilentlyWhenNoTokensFound(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->liveConnections = $this->createMock(LiveConnectionsPortInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture();

        $tokenId = $this->generateValidTokenIdString();

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->accessTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->accessTokenRepository
            ->expects($this->never())
            ->method('revokeByChainId');

        $this->refreshTokenRepository
            ->expects($this->never())
            ->method('revokeByChainId');

        $this->connection
            ->expects($this->never())
            ->method('transactional');

        $this->liveConnections
            ->expects($this->never())
            ->method('closeForAccessTokens');

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: true));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testChainRevocationOfAChainlessAccessTokenRevokesThatTokenAlone(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->liveConnections = $this->createMock(LiveConnectionsPortInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture();

        $accessToken = $this->createAccessToken(null);
        $tokenId = $accessToken->getTokenId()->toString();

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->accessTokenRepository
            ->method('findByTokenId')
            ->willReturn($accessToken);

        $this->accessTokenRepository
            ->expects($this->never())
            ->method('revokeByChainId');

        $this->refreshTokenRepository
            ->expects($this->never())
            ->method('revokeByChainId');

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->identicalTo($accessToken));

        $this->liveConnections
            ->expects($this->never())
            ->method('closeForAccessTokens');

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: true));

        $this->assertTrue($accessToken->isRevoked());
    }

    // --- Live connections ---

    #[AllowMockObjectsWithoutExpectations]
    public function testNoWebServerInThisContainerIsLoggedAndTheRevocationCompletes(): void
    {
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('No web server runs in this container'));
        $this->handler = $this->createRevokeTokenHandlerFixture($logger);

        $chainId = ChainId::generate();
        $accessToken = $this->createAccessToken($chainId);
        $refreshToken = RefreshToken::issue($accessToken, $chainId);

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($refreshToken);
        $this->accessTokenRepository->method('findTokenIdsByChainId')->willReturn([$accessToken->getTokenId()->toString()]);
        $this->refreshTokenRepository->expects($this->once())->method('revokeByChainId');
        $this->liveConnections->method('closeForAccessTokens')->willThrowException(new ServerNotRunningException());

        ($this->handler)(new RevokeTokenCommand($refreshToken->getTokenId()->toString(), revokeChain: true));
    }

    #[AllowMockObjectsWithoutExpectations]
    /** The chain is revoked, so the command completes; the error names the chain and the count, never a token. */
    public function testAWebServerThatDidNotCloseTheConnectionsIsLoggedWithTheTokenIdsAndTheRevocationCompletes(): void
    {
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture($logger);

        $chainId = ChainId::generate();
        $accessToken = $this->createAccessToken($chainId);
        $refreshToken = RefreshToken::issue($accessToken, $chainId);
        $tokenId = $accessToken->getTokenId()->toString();

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($refreshToken);
        $this->accessTokenRepository->method('findTokenIdsByChainId')->willReturn([$tokenId]);
        $this->refreshTokenRepository->expects($this->once())->method('revokeByChainId');
        $this->liveConnections->method('closeForAccessTokens')->willThrowException(new ServerControlException('a worker did not answer'));
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('did not close'),
            $this->callback(static fn (array $context): bool => ($context['chain'] ?? null) === $chainId->toString()
                && ($context['connections'] ?? null) === 1
                && $context['exception'] instanceof ServerControlException),
        );

        ($this->handler)(new RevokeTokenCommand($refreshToken->getTokenId()->toString(), revokeChain: true));
    }

    // --- Ownership (RFC 7009 section 2.2: a foreign token is answered like an unknown one) ---

    public function testAnotherUsersTokenIsLeftAloneAndNoSocketCloses(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->liveConnections = $this->createMock(LiveConnectionsPortInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture();

        $chainId = ChainId::generate();
        $accessToken = $this->createAccessToken($chainId, $this->createUser());
        $refreshToken = RefreshToken::issue($accessToken, $chainId);

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($refreshToken);
        $this->refreshTokenRepository->expects($this->never())->method('revokeByChainId');
        $this->refreshTokenRepository->expects($this->never())->method('save');
        $this->accessTokenRepository->expects($this->never())->method('revokeByChainId');
        $this->accessTokenRepository->expects($this->never())->method('save');
        $this->connection->expects($this->never())->method('transactional');
        $this->liveConnections->expects($this->never())->method('closeForAccessTokens');

        ($this->handler)(new RevokeTokenCommand(
            $refreshToken->getTokenId()->toString(),
            revokeChain: true,
            ownerUserId: $this->createUser()->getId()->toString(),
        ));

        $this->assertFalse($refreshToken->isRevoked());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testATokenWithoutAUserNeverBelongsToTheOwner(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture();

        $accessToken = $this->createAccessToken(null);

        $this->refreshTokenRepository->method('findByTokenId')->willReturn(null);
        $this->accessTokenRepository->method('findByTokenId')->willReturn($accessToken);
        $this->accessTokenRepository->expects($this->never())->method('save');

        ($this->handler)(new RevokeTokenCommand(
            $accessToken->getTokenId()->toString(),
            revokeChain: true,
            ownerUserId: $this->createUser()->getId()->toString(),
        ));

        $this->assertFalse($accessToken->isRevoked());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testTheOwnersOwnTokenRevokesItsChainAndClosesItsSockets(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->liveConnections = $this->createMock(LiveConnectionsPortInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture();

        $owner = $this->createUser();
        $chainId = ChainId::generate();
        $accessToken = $this->createAccessToken($chainId, $owner);
        $refreshToken = RefreshToken::issue($accessToken, $chainId);

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($refreshToken);
        $this->accessTokenRepository->method('findTokenIdsByChainId')->willReturn([$accessToken->getTokenId()->toString()]);
        $this->refreshTokenRepository->expects($this->once())->method('revokeByChainId');
        $this->accessTokenRepository->expects($this->once())->method('revokeByChainId');
        $this->liveConnections
            ->expects($this->once())
            ->method('closeForAccessTokens')
            ->with([$accessToken->getTokenId()->toString()])
            ->willReturn(1);

        ($this->handler)(new RevokeTokenCommand(
            $refreshToken->getTokenId()->toString(),
            revokeChain: true,
            ownerUserId: $owner->getId()->toString(),
        ));
    }

    // --- Single token revocation ---

    public function testSingleRefreshTokenRevocationClosesNoSocket(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->liveConnections = $this->createMock(LiveConnectionsPortInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture();

        $chainId = ChainId::generate();
        $accessToken = $this->createAccessToken($chainId);
        $refreshToken = RefreshToken::issue($accessToken, $chainId);
        $tokenId = $refreshToken->getTokenId()->toString();

        $this->refreshTokenRepository
            ->expects($this->once())
            ->method('findByTokenId')
            ->with($this->callback(fn (TokenId $id): bool => $id->toString() === $tokenId))
            ->willReturn($refreshToken);

        $this->refreshTokenRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->identicalTo($refreshToken));

        $this->refreshTokenRepository
            ->expects($this->never())
            ->method('revokeByChainId');

        $this->accessTokenRepository
            ->expects($this->never())
            ->method('findByTokenId');

        $this->liveConnections
            ->expects($this->never())
            ->method('closeForAccessTokens');

        $this->connection
            ->expects($this->once())
            ->method('transactional');

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: false));

        $this->assertTrue($refreshToken->isRevoked());
    }

    public function testSingleAccessTokenRevocation(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->liveConnections = $this->createMock(LiveConnectionsPortInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture();

        $accessToken = $this->createAccessToken(null);
        $tokenId = $accessToken->getTokenId()->toString();

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('findByTokenId')
            ->with($this->callback(fn (TokenId $id): bool => $id->toString() === $tokenId))
            ->willReturn($accessToken);

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->identicalTo($accessToken));

        $this->liveConnections
            ->expects($this->never())
            ->method('closeForAccessTokens');

        $this->connection
            ->expects($this->once())
            ->method('transactional');

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: false));

        $this->assertTrue($accessToken->isRevoked());
    }

    public function testRfc7009SilentReturnWhenTokenNotFound(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture();

        $tokenId = $this->generateValidTokenIdString();

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->accessTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->refreshTokenRepository
            ->expects($this->never())
            ->method('save');

        $this->accessTokenRepository
            ->expects($this->never())
            ->method('save');

        $this->connection
            ->expects($this->never())
            ->method('transactional');

        // Should not throw: RFC 7009 requires silent 200 OK
        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: false));
    }

    // --- Transaction wrapping ---

    public function testChainRevocationWrapsInTransaction(): void
    {
        $chainId = ChainId::generate();
        $accessToken = $this->createAccessToken($chainId);
        $refreshToken = RefreshToken::issue($accessToken, $chainId);

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn($refreshToken);
        $this->accessTokenRepository
            ->method('findTokenIdsByChainId')
            ->willReturn([$accessToken->getTokenId()->toString()]);

        $transactionalCalled = false;
        $this->connection
            ->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) use (&$transactionalCalled): mixed {
                $transactionalCalled = true;

                return $callback();
            });

        ($this->handler)(new RevokeTokenCommand($refreshToken->getTokenId()->toString(), revokeChain: true));

        $this->assertTrue($transactionalCalled, 'transactional() should have been called on the connection');
    }

    public function testSingleRefreshTokenRevocationWrapsInTransaction(): void
    {
        $accessToken = $this->createAccessToken(null);
        $refreshToken = RefreshToken::issue($accessToken);

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn($refreshToken);

        $transactionalCalled = false;
        $this->connection
            ->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) use (&$transactionalCalled): mixed {
                $transactionalCalled = true;

                return $callback();
            });

        ($this->handler)(new RevokeTokenCommand($refreshToken->getTokenId()->toString(), revokeChain: false));

        $this->assertTrue($transactionalCalled, 'transactional() should have been called on the connection');
    }

    public function testSingleAccessTokenRevocationWrapsInTransaction(): void
    {
        $accessToken = $this->createAccessToken(null);

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->accessTokenRepository
            ->method('findByTokenId')
            ->willReturn($accessToken);

        $transactionalCalled = false;
        $this->connection
            ->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) use (&$transactionalCalled): mixed {
                $transactionalCalled = true;

                return $callback();
            });

        ($this->handler)(new RevokeTokenCommand($accessToken->getTokenId()->toString(), revokeChain: false));

        $this->assertTrue($transactionalCalled, 'transactional() should have been called on the connection');
    }

    // --- RevokeChain defaults to false ---

    public function testRevokeChainDefaultsToFalse(): void
    {
        $this->connection->expects($this->never())->method('transactional');

        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->handler = $this->createRevokeTokenHandlerFixture();

        $tokenId = $this->generateValidTokenIdString();

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->accessTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        // Default should be single revocation, not chain
        $this->accessTokenRepository
            ->expects($this->never())
            ->method('revokeByChainId');

        ($this->handler)(new RevokeTokenCommand($tokenId));
    }

    // --- Helpers ---

    private function createAccessToken(?ChainId $chainId, ?User $user = null): AccessToken
    {
        return AccessToken::issue(
            client: Client::create(
                name: 'Test Client',
                redirectUris: ['https://player.baander.app/callback'],
            ),
            user: $user,
            chainId: $chainId,
        );
    }

    private function createUser(): User
    {
        return User::register(new Email('listener-' . bin2hex(random_bytes(4)) . '@baander.app'), 'hashed-password', 'Listener');
    }

    private function generateValidTokenIdString(): string
    {
        return TokenId::generate()->toString();
    }
}
