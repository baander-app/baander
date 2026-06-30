<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\OAuth\RevokeTokenCommand;
use App\Auth\Application\CommandHandler\OAuth\RevokeTokenHandler;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class RevokeTokenHandlerTest extends TestCase
{
    private AccessTokenRepositoryInterface&MockObject $accessTokenRepository;
    private RefreshTokenRepositoryInterface&MockObject $refreshTokenRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&MockObject $connection;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private RevokeTokenHandler $handler;

    protected function setUp(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);

        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('transactional')->willReturnCallback(
            static fn (callable $callback) => $callback(),
        );

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);

        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->handler = new RevokeTokenHandler(
            $this->accessTokenRepository,
            $this->refreshTokenRepository,
            $this->entityManager,
            $this->eventDispatcher,
        );
    }

    // --- Chain revocation tests ---

    public function testChainRevocationViaRefreshToken(): void
    {
        $chainId = ChainId::generate();
        $tokenId = $this->generateValidTokenIdString();

        $accessToken = $this->createAccessToken($chainId);
        $refreshToken = RefreshToken::issue($accessToken, $chainId);

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->with($this->callback(fn (TokenId $id): bool => $id->toString() === $tokenId))
            ->willReturn($refreshToken);

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('revokeByChainId')
            ->with($this->callback(fn (ChainId $c): bool => $c->equals($chainId)));

        $this->refreshTokenRepository
            ->expects($this->once())
            ->method('revokeByChainId')
            ->with($this->callback(fn (ChainId $c): bool => $c->equals($chainId)));

        $this->connection
            ->expects($this->once())
            ->method('transactional');

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: true));
    }

    public function testChainRevocationViaAccessToken(): void
    {
        $chainId = ChainId::generate();
        $tokenId = $this->generateValidTokenIdString();

        $accessToken = $this->createAccessToken($chainId);

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->accessTokenRepository
            ->method('findByTokenId')
            ->with($this->callback(fn (TokenId $id): bool => $id->toString() === $tokenId))
            ->willReturn($accessToken);

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('revokeByChainId')
            ->with($this->callback(fn (ChainId $c): bool => $c->equals($chainId)));

        $this->refreshTokenRepository
            ->expects($this->once())
            ->method('revokeByChainId')
            ->with($this->callback(fn (ChainId $c): bool => $c->equals($chainId)));

        $this->connection
            ->expects($this->once())
            ->method('transactional');

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: true));
    }

    public function testChainRevocationReturnsSilentlyWhenNoTokensFound(): void
    {
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

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: true));
    }

    public function testChainRevocationDoesNothingWhenAccessTokenHasNoChainId(): void
    {
        $tokenId = $this->generateValidTokenIdString();

        $accessToken = $this->createAccessToken(null);

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

        $this->connection
            ->expects($this->never())
            ->method('transactional');

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: true));
    }

    // --- Single token revocation tests ---

    public function testSingleRefreshTokenRevocation(): void
    {
        $tokenId = $this->generateValidTokenIdString();

        $accessToken = $this->createAccessToken(null);
        $refreshToken = RefreshToken::issue($accessToken);

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->with($this->callback(fn (TokenId $id): bool => $id->toString() === $tokenId))
            ->willReturn($refreshToken);

        $this->refreshTokenRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->identicalTo($refreshToken));

        $this->accessTokenRepository
            ->expects($this->never())
            ->method('findByTokenId');

        $this->connection
            ->expects($this->once())
            ->method('transactional');

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: false));

        $this->assertTrue($refreshToken->isRevoked());
    }

    public function testSingleAccessTokenRevocation(): void
    {
        $tokenId = $this->generateValidTokenIdString();

        $accessToken = $this->createAccessToken(null);

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->accessTokenRepository
            ->method('findByTokenId')
            ->with($this->callback(fn (TokenId $id): bool => $id->toString() === $tokenId))
            ->willReturn($accessToken);

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->identicalTo($accessToken));

        $this->connection
            ->expects($this->once())
            ->method('transactional');

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: false));

        $this->assertTrue($accessToken->isRevoked());
    }

    public function testRfc7009SilentReturnWhenTokenNotFound(): void
    {
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

        // Should not throw — RFC 7009 requires silent 200 OK
        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: false));
    }

    // --- Transaction wrapping ---

    public function testChainRevocationWrapsInTransaction(): void
    {
        $chainId = ChainId::generate();
        $tokenId = $this->generateValidTokenIdString();

        $accessToken = $this->createAccessToken($chainId);
        $refreshToken = RefreshToken::issue($accessToken, $chainId);

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn($refreshToken);

        $transactionalCalled = false;
        $this->connection
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) use (&$transactionalCalled): void {
                $transactionalCalled = true;
                $callback();
            });

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: true));

        $this->assertTrue($transactionalCalled, 'transactional() should have been called on the connection');
    }

    public function testSingleRefreshTokenRevocationWrapsInTransaction(): void
    {
        $tokenId = $this->generateValidTokenIdString();

        $accessToken = $this->createAccessToken(null);
        $refreshToken = RefreshToken::issue($accessToken);

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn($refreshToken);

        $transactionalCalled = false;
        $this->connection
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) use (&$transactionalCalled): void {
                $transactionalCalled = true;
                $callback();
            });

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: false));

        $this->assertTrue($transactionalCalled, 'transactional() should have been called on the connection');
    }

    public function testSingleAccessTokenRevocationWrapsInTransaction(): void
    {
        $tokenId = $this->generateValidTokenIdString();

        $accessToken = $this->createAccessToken(null);

        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturn(null);

        $this->accessTokenRepository
            ->method('findByTokenId')
            ->willReturn($accessToken);

        $transactionalCalled = false;
        $this->connection
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) use (&$transactionalCalled): void {
                $transactionalCalled = true;
                $callback();
            });

        ($this->handler)(new RevokeTokenCommand($tokenId, revokeChain: false));

        $this->assertTrue($transactionalCalled, 'transactional() should have been called on the connection');
    }

    // --- RevokeChain defaults to false ---

    public function testRevokeChainDefaultsToFalse(): void
    {
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

    private function createAccessToken(?ChainId $chainId): AccessToken
    {
        return AccessToken::issue(
            client: Client::create(
                name: 'Test Client',
                redirectUris: ['http://localhost'],
            ),
            chainId: $chainId,
        );
    }

    private function generateValidTokenIdString(): string
    {
        return TokenId::generate()->toString();
    }
}
