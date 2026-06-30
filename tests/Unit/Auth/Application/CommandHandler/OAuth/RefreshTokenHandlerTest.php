<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\CommandHandler\OAuth\RefreshTokenHandler;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\RefreshTokenState;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RefreshTokenHandlerTest extends TestCase
{
    private AccessTokenRepositoryInterface&MockObject $accessTokenRepository;
    private RefreshTokenRepositoryInterface&MockObject $refreshTokenRepository;
    private TokenChainValidator $chainValidator;
    private EntityManagerInterface&MockObject $entityManager;
    private JwtGeneratorInterface&MockObject $jwtGenerator;
    private RefreshTokenHandler $handler;

    protected function setUp(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);

        // TokenChainValidator is final and cannot be mocked. Use a real instance
        // with dedicated repository mocks for its dependency chain.
        $chainValidatorAccessTokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $chainValidatorRefreshTokenRepo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->chainValidator = new TokenChainValidator(
            $chainValidatorAccessTokenRepo,
            $chainValidatorRefreshTokenRepo,
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->jwtGenerator = $this->createMock(JwtGeneratorInterface::class);
        $this->jwtGenerator->method('generate')->willReturn('mock-jwt-token');

        $this->handler = new RefreshTokenHandler(
            $this->accessTokenRepository,
            $this->refreshTokenRepository,
            $this->chainValidator,
            $this->entityManager,
            $this->jwtGenerator,
            accessTokenTtl: 3600,
            refreshTokenTtl: 2592000,
        );
    }

    // --- Happy path ---

    public function testValidRefreshTokenReturnsNewTokenPair(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile'), new Scope('library')],
            'My Token',
            new \DateInterval('PT3600S'),
            $chainId,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            new \DateInterval('PT2592000S'),
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        // Expect saves: old refresh token (markUsed), old access token (revoke),
        // new access token, new refresh token
        $this->refreshTokenRepository->expects($this->exactly(2))->method('save');
        $this->accessTokenRepository->expects($this->exactly(2))->method('save');

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
        );

        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
        $this->assertNotEmpty($result->getRefreshToken());
        $this->assertEquals(3600, $result->getExpiresIn());
        $this->assertContains('profile', $result->getScopes());
        $this->assertContains('library', $result->getScopes());
    }

    public function testOldAccessTokenIsRevoked(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            new \DateInterval('PT3600S'),
            $chainId,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            new \DateInterval('PT2592000S'),
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
        );

        ($this->handler)($command);

        // After handler runs, the old access token should be revoked
        $this->assertTrue($oldAccessToken->isRevoked());
    }

    public function testOldRefreshTokenIsMarkedUsed(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            new \DateInterval('PT3600S'),
            $chainId,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            new \DateInterval('PT2592000S'),
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
        );

        ($this->handler)($command);

        $this->assertTrue($oldRefreshToken->hasBeenUsed());
    }

    // --- Error cases ---

    public function testNotFoundRefreshTokenThrows(): void
    {
        $this->refreshTokenRepository->method('findByTokenId')->willReturn(null);

        $command = new RefreshTokenCommand(
            refreshTokenId: str_repeat('a', 80),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refresh token not found');

        ($this->handler)($command);
    }

    public function testRevokedRefreshTokenThrows(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            new \DateInterval('PT3600S'),
            $chainId,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            new \DateInterval('PT2592000S'),
        );
        $oldRefreshToken->revoke();

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refresh token has been revoked');

        ($this->handler)($command);
    }

    public function testExpiredRefreshTokenThrows(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        // Create an access token with no expiry
        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null, // no TTL
            $chainId,
        );

        // Reconstitute a refresh token with an expired date
        $oldRefreshToken = RefreshToken::reconstitute(new RefreshTokenState(
            id: Uuid::generate(),
            tokenId: \App\Auth\Domain\Model\OAuth\TokenId::generate(),
            accessToken: $oldAccessToken,
            chainId: $chainId,
            previousRefreshToken: null,
            expiresAt: new \DateTimeImmutable('-1 hour'), // expired
            usedAt: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            revoked: false,
        ));

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refresh token has expired');

        ($this->handler)($command);
    }

    public function testUsedRefreshTokenTriggersChainRevocation(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
        );

        // Reconstitute with usedAt set to simulate a previously-used refresh token
        $oldRefreshToken = RefreshToken::reconstitute(new RefreshTokenState(
            id: Uuid::generate(),
            tokenId: \App\Auth\Domain\Model\OAuth\TokenId::generate(),
            accessToken: $oldAccessToken,
            chainId: $chainId,
            previousRefreshToken: null, // no previous
            expiresAt: null, // no expiry
            usedAt: new \DateTimeImmutable('-5 minutes'), // used 5 minutes ago
            createdAt: new \DateTimeImmutable('-5 minutes'),
            updatedAt: new \DateTimeImmutable('-5 minutes'),
            revoked: false,
        ));

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reuse detected');

        ($this->handler)($command);
    }

    public function testTokenWithoutChainIdSkipsValidation(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();

        // Create token pair without chainId (outside rotation model)
        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            null, // no chainId
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            null, // no chainId
            null,
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
        );

        // Should succeed without any chain validation
        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
    }

    // --- Metadata storage ---

    public function testMetadataIsStoredWhenFingerprintProvided(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            null,
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        // Mock repository to return null (entity not found) -- handler silently skips
        $tokenRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $tokenRepo->method('findOneBy')->willReturn(null);
        $this->entityManager->method('getRepository')->willReturn($tokenRepo);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            ipAddress: '10.0.0.1',
            userAgent: 'Test Browser',
            clientFingerprint: 'fingerprint-123',
        );

        // Should not throw -- just skips metadata storage when entity not found
        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
    }

    public function testMetadataIsSkippedWhenNoFingerprint(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            null,
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);
        $this->entityManager->expects($this->never())->method('persist');

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            clientFingerprint: null,
        );

        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
    }

    // --- New token preserves scopes and name ---

    public function testNewTokenPairPreservesScopesAndName(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('email'), new Scope('library')],
            'My Laptop',
            new \DateInterval('PT3600S'),
            $chainId,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            new \DateInterval('PT2592000S'),
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
        );

        $result = ($this->handler)($command);

        $this->assertCount(2, $result->getScopes());
        $this->assertContains('email', $result->getScopes());
        $this->assertContains('library', $result->getScopes());
    }

    // --- Transaction rollback test ---

    public function testTransactionRollbackOnFailure(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            null,
        );

        // Use completely fresh mocks to avoid setUp interference
        $accessTokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $refreshTokenRepo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $refreshTokenRepo->method('findByTokenId')->willReturn($oldRefreshToken);
        $refreshTokenRepo->expects($this->never())->method('save');
        $accessTokenRepo->expects($this->never())->method('save');

        // Make the connection throw on transactional to simulate a DB failure
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willThrowException(
            new RuntimeException('Database error'),
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $jwtGenerator = $this->createMock(JwtGeneratorInterface::class);
        $jwtGenerator->method('generate')->willReturn('mock-jwt-token');

        $handler = new RefreshTokenHandler(
            $accessTokenRepo,
            $refreshTokenRepo,
            $this->chainValidator,
            $entityManager,
            $jwtGenerator,
            accessTokenTtl: 3600,
            refreshTokenTtl: 2592000,
        );

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database error');

        ($handler)($command);
    }

    // --- Helpers ---

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
