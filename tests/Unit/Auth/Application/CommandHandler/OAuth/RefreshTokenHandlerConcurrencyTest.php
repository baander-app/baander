<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\CommandHandler\OAuth\RefreshTokenHandler;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\RefreshTokenState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Validation test: refresh-token one-time-use must be concurrency-safe.
 *
 * The handler loads the token, checks hasBeenUsed() in memory, then marks it
 * used inside the transaction. With no SELECT FOR UPDATE / optimistic locking,
 * two concurrent requests can both read the token as unused and both issue new
 * token pairs. This test simulates that race by returning a fresh, unused token
 * on every read, and expects the second invocation to be rejected.
 */
final class RefreshTokenHandlerConcurrencyTest extends TestCase
{
    private AccessTokenRepositoryInterface&Stub $accessTokenRepository;
    private RefreshTokenRepositoryInterface&Stub $refreshTokenRepository;
    private EntityManagerInterface&Stub $entityManager;
    private RefreshTokenHandler $handler;
    private int $consumeCallCount = 0;

    protected function setUp(): void
    {
        $this->accessTokenRepository = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createStub(RefreshTokenRepositoryInterface::class);

        $chainValidatorAccessTokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $chainValidatorRefreshTokenRepo = $this->createStub(RefreshTokenRepositoryInterface::class);
        $chainValidator = new TokenChainValidator(
            $chainValidatorAccessTokenRepo,
            $chainValidatorRefreshTokenRepo,
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn(callable $callback) => $callback());
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $jwtGenerator = $this->createStub(JwtGeneratorInterface::class);
        $jwtGenerator->method('generate')->willReturn('mock-jwt');

        $this->handler = new RefreshTokenHandler(
            $this->accessTokenRepository,
            $this->refreshTokenRepository,
            $chainValidator,
            $this->entityManager,
            $jwtGenerator,
            accessTokenTtl: 3600,
            refreshTokenTtl: 2592000,
        );
    }

    public function testConcurrentRefreshTokenUseIsRejected(): void
    {
        $tokenIdString = 'same-refresh-token-id-used-twice-concurrently';
        $client = $this->createConfidentialClient();
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $chainId = ChainId::generate();

        // Simulate two concurrent requests reading the same DB row before either
        // writes. Each invocation must get its own object so that in-memory
        // mutation from the first call does not hide the bug.
        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturnCallback(function () use ($client, $user, $chainId, $tokenIdString): RefreshToken {
                $accessToken = AccessToken::issue(
                    $client,
                    $user,
                    [new Scope('profile')],
                    null,
                    null,
                    $chainId,
                );

                return RefreshToken::reconstitute(new RefreshTokenState(
                    id: Uuid::generate(),
                    tokenId: TokenId::fromString($tokenIdString),
                    accessToken: $accessToken,
                    chainId: $chainId,
                    previousRefreshToken: null,
                    expiresAt: null,
                    usedAt: null,
                    createdAt: new \DateTimeImmutable(),
                    updatedAt: new \DateTimeImmutable(),
                    revoked: false,
                ));
            });

        $this->consumeCallCount = 0;
        $this->refreshTokenRepository
            ->method('consumeByTokenId')
            ->willReturnCallback(function (TokenId $tokenId) use ($client, $user, $chainId, $tokenIdString): ?RefreshToken {
                $this->consumeCallCount++;
                if ($this->consumeCallCount > 1) {
                    return null;
                }

                $accessToken = AccessToken::issue(
                    $client,
                    $user,
                    [new Scope('profile')],
                    null,
                    null,
                    $chainId,
                );

                return RefreshToken::reconstitute(new RefreshTokenState(
                    id: Uuid::generate(),
                    tokenId: TokenId::fromString($tokenIdString),
                    accessToken: $accessToken,
                    chainId: $chainId,
                    previousRefreshToken: null,
                    expiresAt: null,
                    usedAt: new \DateTimeImmutable(),
                    createdAt: new \DateTimeImmutable(),
                    updatedAt: new \DateTimeImmutable(),
                    revoked: false,
                ));
            });

        $command = new RefreshTokenCommand(refreshTokenId: $tokenIdString);

        // First request succeeds.
        $first = ($this->handler)($command);
        $this->assertNotEmpty($first->getAccessToken());
        $this->assertNotEmpty($first->getRefreshToken());

        // Second concurrent request should be rejected because the token was
        // already consumed. With the current implementation it succeeds, so
        // this assertion fails and proves the race condition.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reuse detected');

        ($this->handler)($command);
    }

    private function createConfidentialClient(): Client
    {
        return Client::create(
            name: 'Test App',
            redirectUris: ['http://localhost'],
            secret: 'test-secret',
            confidential: true,
            firstParty: true,
        );
    }
}
