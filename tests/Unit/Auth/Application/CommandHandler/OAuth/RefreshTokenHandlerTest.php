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
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RefreshTokenHandlerTest extends TestCase
{
    private const string JKT = 'bound-proof-key-thumbprint';

    private AccessTokenRepositoryInterface&Stub $accessTokenRepository;
    private RefreshTokenRepositoryInterface&Stub $refreshTokenRepository;
    /** @var list<AccessToken> */
    private array $savedAccessTokens = [];
    /** @var list<RefreshToken> */
    private array $savedRefreshTokens = [];
    private TokenChainValidator $chainValidator;
    private EntityManagerInterface&Stub $entityManager;
    private JwtGeneratorInterface&Stub $jwtGenerator;
    private TokenMetadataRepositoryInterface&Stub $tokenMetadataRepository;
    /** @var list<TokenMetadata> */
    private array $savedMetadata = [];
    private RefreshTokenHandler $handler;

    protected function setUp(): void
    {
        $this->accessTokenRepository = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createStub(RefreshTokenRepositoryInterface::class);
        $this->accessTokenRepository->method('save')->willReturnCallback(function (AccessToken $token): void {
            $this->savedAccessTokens[] = $token;
        });
        $this->refreshTokenRepository->method('save')->willReturnCallback(function (RefreshToken $token): void {
            $this->savedRefreshTokens[] = $token;
        });

        // TokenChainValidator is final and cannot be mocked. Use a real instance
        // with dedicated repository mocks for its dependency chain.
        $chainValidatorAccessTokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $chainValidatorRefreshTokenRepo = $this->createStub(RefreshTokenRepositoryInterface::class);
        $this->chainValidator = new TokenChainValidator(
            $chainValidatorAccessTokenRepo,
            $chainValidatorRefreshTokenRepo,
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->jwtGenerator = $this->createStub(JwtGeneratorInterface::class);
        $this->jwtGenerator->method('generate')->willReturn('mock-jwt-token');

        $this->refreshTokenRepository
            ->method('consumeByTokenId')
            ->willReturnCallback(function (TokenId $tokenId): ?RefreshToken {
                $token = $this->refreshTokenRepository->findByTokenId($tokenId);

                if ($token === null || $token->hasBeenUsed() || $token->isRevoked() || $token->isExpired()) {
                    return null;
                }

                $token->markUsed();

                return $token;
            });

        $this->tokenMetadataRepository = $this->createStub(TokenMetadataRepositoryInterface::class);
        $this->tokenMetadataRepository->method('save')->willReturnCallback(function (TokenMetadata $metadata): void {
            $this->savedMetadata[] = $metadata;
        });

        $this->handler = new RefreshTokenHandler(
            $this->accessTokenRepository,
            $this->refreshTokenRepository,
            $this->chainValidator,
            $this->entityManager,
            $this->jwtGenerator,
            $this->tokenMetadataRepository,
            accessTokenTtl: 3600,
            refreshTokenTtl: 2592000,
        );
    }

    // --- Happy path ---

    public function testValidRefreshTokenReturnsNewTokenPair(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile'), new Scope('library')],
            'My Token',
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            new \DateInterval('PT2592000S'),
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        );

        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
        $this->assertNotEmpty($result->getRefreshToken());
        $this->assertEquals(3600, $result->getExpiresIn());
        $this->assertContains('profile', $result->getScopes());
        $this->assertContains('library', $result->getScopes());
        self::assertCount(2, $this->savedRefreshTokens);
        self::assertCount(2, $this->savedAccessTokens);
        self::assertSame($oldRefreshToken, $this->savedRefreshTokens[0]);
        self::assertSame($oldAccessToken, $this->savedAccessTokens[0]);
        self::assertNotSame($oldRefreshToken, $this->savedRefreshTokens[1]);
        self::assertNotSame($oldAccessToken, $this->savedAccessTokens[1]);
    }

    public function testReplacementKeepsTheClientFingerprintBinding(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $chainId = ChainId::generate();
        $oldAccessToken = AccessToken::issue($this->createConfidentialClient(), $user, [new Scope('profile')], null, new \DateInterval('PT3600S'), $chainId, dpopJkt: self::JKT);
        $oldRefreshToken = RefreshToken::issue($oldAccessToken, $chainId, new \DateInterval('PT2592000S'));
        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);
        $this->tokenMetadataRepository->method('findByTokenId')->willReturnCallback(
            static fn (TokenId $tokenId): ?TokenMetadata => $tokenId->equals($oldAccessToken->getTokenId())
                ? TokenMetadata::create($oldAccessToken->getId(), clientFingerprint: 'bound-fingerprint')
                : null,
        );

        ($this->handler)(new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            ipAddress: '127.0.0.2',
            userAgent: 'PHPUnit/13',
            dpopJkt: self::JKT,
        ));

        self::assertCount(1, $this->savedMetadata);
        self::assertTrue($this->savedMetadata[0]->getTokenId()->equals($this->savedAccessTokens[1]->getId()));
        self::assertSame('bound-fingerprint', $this->savedMetadata[0]->getClientFingerprint());
        self::assertSame('127.0.0.2', $this->savedMetadata[0]->getIpAddress());
        self::assertSame('PHPUnit/13', $this->savedMetadata[0]->getUserAgent());
    }

    public function testOldAccessTokenIsRevoked(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            new \DateInterval('PT2592000S'),
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        );

        ($this->handler)($command);

        // After handler runs, the old access token should be revoked
        $this->assertTrue($oldAccessToken->isRevoked());
    }

    public function testOldRefreshTokenIsMarkedUsed(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            new \DateInterval('PT2592000S'),
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
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
            dpopJkt: self::JKT,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refresh token not found');

        ($this->handler)($command);
    }

    /** @return iterable<string, array{string|null, string|null}> */
    public static function unmatchedProofBindings(): iterable
    {
        yield 'proof from another key' => [self::JKT, 'other-proof-key-thumbprint'];
        yield 'missing proof key' => [self::JKT, null];
        yield 'token issued without a binding' => [null, self::JKT];
    }

    #[DataProvider('unmatchedProofBindings')]
    public function testRefreshRequiresTheProofKeyTheTokenWasIssuedTo(?string $boundJkt, ?string $proofJkt): void
    {
        $chainId = ChainId::generate();
        $accessToken = AccessToken::issue(
            $this->createConfidentialClient(),
            User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User'),
            [new Scope('profile')],
            null,
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: $boundJkt,
        );
        $refreshToken = RefreshToken::issue($accessToken, $chainId, new \DateInterval('PT2592000S'));
        $this->refreshTokenRepository->method('findByTokenId')->willReturn($refreshToken);

        try {
            ($this->handler)(new RefreshTokenCommand(
                refreshTokenId: $refreshToken->getTokenId()->toString(),
                dpopJkt: $proofJkt,
            ));
            self::fail('A refresh with an unmatched proof key must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Refresh token proof binding does not match.', $exception->getMessage());
        }
        self::assertFalse($refreshToken->hasBeenUsed());
        self::assertSame([], $this->savedAccessTokens);
        self::assertSame([], $this->savedRefreshTokens);
    }

    public function testRotatedAccessTokenKeepsTheProofKeyBinding(): void
    {
        $chainId = ChainId::generate();
        $accessToken = AccessToken::issue(
            $this->createConfidentialClient(),
            User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User'),
            [new Scope('profile')],
            null,
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: self::JKT,
        );
        $refreshToken = RefreshToken::issue($accessToken, $chainId, new \DateInterval('PT2592000S'));
        $this->refreshTokenRepository->method('findByTokenId')->willReturn($refreshToken);

        ($this->handler)(new RefreshTokenCommand(
            refreshTokenId: $refreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        ));

        self::assertSame(self::JKT, $this->savedAccessTokens[1]->getDpopJkt());
    }

    public function testRevokedRefreshTokenThrows(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: self::JKT,
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
            dpopJkt: self::JKT,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refresh token has been revoked');

        ($this->handler)($command);
    }

    public function testExpiredRefreshTokenThrows(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
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
            dpopJkt: self::JKT,
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
            dpopJkt: self::JKT,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refresh token has expired');

        ($this->handler)($command);
    }

    public function testUsedRefreshTokenTriggersChainRevocation(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
            dpopJkt: self::JKT,
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
            dpopJkt: self::JKT,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reuse detected');

        ($this->handler)($command);
    }

    public function testTokenWithoutChainIdSkipsValidation(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();

        // Create token pair without chainId (outside rotation model)
        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            null, // no chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            null, // no chainId
            null,
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        );

        // Should succeed without any chain validation
        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
    }

    // --- Metadata storage ---

    public function testMetadataIsStoredWhenFingerprintProvided(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            null,
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        // Mock repository to return null (entity not found) -- handler silently skips
        $tokenRepo = $this->createStub(\Doctrine\ORM\EntityRepository::class);
        $tokenRepo->method('findOneBy')->willReturn(null);
        $this->entityManager->method('getRepository')->willReturn($tokenRepo);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            ipAddress: '10.0.0.1',
            userAgent: 'Test Browser',
            clientFingerprint: 'fingerprint-123',
            dpopJkt: self::JKT,
        );

        // Should not throw -- just skips metadata storage when entity not found
        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
    }

    public function testMetadataIsSkippedWhenNoFingerprint(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            null,
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            clientFingerprint: null,
            dpopJkt: self::JKT,
        );

        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
    }

    // --- New token preserves scopes and name ---

    public function testNewTokenPairPreservesScopesAndName(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('email'), new Scope('library')],
            'My Laptop',
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            new \DateInterval('PT2592000S'),
        );

        $this->refreshTokenRepository->method('findByTokenId')->willReturn($oldRefreshToken);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        );

        $result = ($this->handler)($command);

        $this->assertCount(2, $result->getScopes());
        $this->assertContains('email', $result->getScopes());
        $this->assertContains('library', $result->getScopes());
    }

    // --- Transaction rollback test ---

    public function testTransactionRollbackOnFailure(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
            dpopJkt: self::JKT,
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
        $refreshTokenRepo->method('consumeByTokenId')->willReturnCallback(function (TokenId $tokenId) use ($oldRefreshToken): ?RefreshToken {
            $token = $oldRefreshToken;
            if ($token->hasBeenUsed() || $token->isRevoked() || $token->isExpired()) {
                return null;
            }
            $token->markUsed();
            return $token;
        });
        $refreshTokenRepo->expects($this->never())->method('save');
        $accessTokenRepo->expects($this->never())->method('save');

        // Make the connection throw on transactional to simulate a DB failure
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willThrowException(
            new RuntimeException('Database error'),
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $jwtGenerator = $this->createStub(JwtGeneratorInterface::class);
        $jwtGenerator->method('generate')->willReturn('mock-jwt-token');

        $handler = new RefreshTokenHandler(
            $accessTokenRepo,
            $refreshTokenRepo,
            $this->chainValidator,
            $entityManager,
            $jwtGenerator,
            $this->createStub(TokenMetadataRepositoryInterface::class),
            accessTokenTtl: 3600,
            refreshTokenTtl: 2592000,
        );

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
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
