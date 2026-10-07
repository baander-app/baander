<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\CommandHandler\OAuth\IssueTokenHandler;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Application\Service\TokenPairIssuer;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IssueTokenHandlerTest extends TestCase
{
    private const string JKT = 'proof-key-thumbprint';

    private ClientRepositoryInterface&Stub $clientRepository;
    private UserRepositoryInterface&Stub $userRepository;
    /** @var list<AccessToken> */
    private array $savedAccessTokens = [];
    /** @var list<RefreshToken> */
    private array $savedRefreshTokens = [];
    /** @var list<TokenMetadata> */
    private array $savedMetadata = [];
    /** @var list<?string> */
    private array $jwtBindings = [];
    private IssueTokenHandler $handler;

    protected function setUp(): void
    {
        $accessTokenRepository = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokenRepository->method('save')->willReturnCallback(function (AccessToken $token): void {
            $this->savedAccessTokens[] = $token;
        });
        $refreshTokenRepository = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokenRepository->method('save')->willReturnCallback(function (RefreshToken $token): void {
            $this->savedRefreshTokens[] = $token;
        });
        $this->clientRepository = $this->createStub(ClientRepositoryInterface::class);
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $jwtGenerator = $this->createStub(JwtGeneratorInterface::class);
        $jwtGenerator->method('generate')->willReturnCallback(function (AccessToken $token, ?string $jkt): string {
            $this->jwtBindings[] = $jkt;

            return 'eyJhbGciOiJSUzI1NiJ9.eyJqdGkiOiJ0ZXN0In0.signature';
        });

        $tokenMetadataRepository = $this->createStub(TokenMetadataRepositoryInterface::class);
        $tokenMetadataRepository->method('save')->willReturnCallback(function (TokenMetadata $metadata): void {
            $this->savedMetadata[] = $metadata;
        });

        $this->handler = new IssueTokenHandler(
            $this->clientRepository,
            $this->userRepository,
            new TokenPairIssuer(
                $accessTokenRepository,
                $refreshTokenRepository,
                new ScopeAllowlist(['profile', 'email', 'library', 'playlist']),
                $entityManager,
                $jwtGenerator,
                $tokenMetadataRepository,
                accessTokenTtl: 3600,
                refreshTokenTtl: 2592000,
            ),
        );
    }

    public function testIssuesAProofBoundTokenPairInANewChain(): void
    {
        [$client, $user] = $this->registered();

        $result = ($this->handler)(new IssueTokenCommand(clientId: $client->getId(), userId: $user->getId(), dpopJkt: self::JKT));

        self::assertNotEmpty($result->getAccessToken());
        self::assertSame($this->savedRefreshTokens[0]->getTokenId()->toString(), $result->getRefreshToken());
        self::assertSame(self::JKT, $this->savedAccessTokens[0]->getDpopJkt());
        self::assertSame([self::JKT], $this->jwtBindings);
        $chain = $this->savedAccessTokens[0]->getChainId()?->toString();
        self::assertNotNull($chain);
        self::assertSame($chain, $this->savedRefreshTokens[0]->getChainId()?->toString());
        self::assertSame($user->getId()->toString(), $this->savedAccessTokens[0]->getUser()?->getId()->toString());
    }

    public function testDropsScopesOutsideTheAllowlist(): void
    {
        [$client, $user] = $this->registered();

        $result = ($this->handler)(new IssueTokenCommand(
            clientId: $client->getId(),
            userId: $user->getId(),
            dpopJkt: self::JKT,
            scopes: ['profile', 'admin', 'library'],
        ));

        self::assertSame(['profile', 'library'], $result->getScopes());
    }

    public function testStoresTokenMetadataWithTheClientFingerprint(): void
    {
        [$client, $user] = $this->registered();

        ($this->handler)(new IssueTokenCommand(
            clientId: $client->getId(),
            userId: $user->getId(),
            dpopJkt: self::JKT,
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit/13',
            clientFingerprint: 'fp-123',
        ));

        self::assertCount(1, $this->savedMetadata);
        self::assertTrue($this->savedMetadata[0]->getTokenId()->equals($this->savedAccessTokens[0]->getId()));
        self::assertSame('PHPUnit/13', $this->savedMetadata[0]->getUserAgent());
        self::assertSame('127.0.0.1', $this->savedMetadata[0]->getIpAddress());
        self::assertSame('fp-123', $this->savedMetadata[0]->getClientFingerprint());
    }

    public function testAnEmptyFingerprintLeavesTheTokenUnbound(): void
    {
        [$client, $user] = $this->registered();

        ($this->handler)(new IssueTokenCommand(clientId: $client->getId(), userId: $user->getId(), dpopJkt: self::JKT, clientFingerprint: ''));

        self::assertNull($this->savedMetadata[0]->getClientFingerprint());
    }

    public function testClientNotFoundThrows(): void
    {
        $this->clientRepository->method('findClientByUuid')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client not found');

        ($this->handler)(new IssueTokenCommand(clientId: Uuid::generate(), userId: Uuid::generate(), dpopJkt: self::JKT));
    }

    public function testRevokedClientThrows(): void
    {
        [$client, $user] = $this->registered();
        $client->revoke();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client has been revoked');

        ($this->handler)(new IssueTokenCommand(clientId: $client->getId(), userId: $user->getId(), dpopJkt: self::JKT));
    }

    public function testUserNotFoundThrows(): void
    {
        $client = Client::create(name: 'SPA', redirectUris: ['http://localhost'], firstParty: true, passwordClient: true);
        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->userRepository->method('findByUuid')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User not found');

        ($this->handler)(new IssueTokenCommand(clientId: $client->getId(), userId: Uuid::generate(), dpopJkt: self::JKT));
    }

    /** @return array{Client, User} */
    private function registered(): array
    {
        $client = Client::create(name: 'SPA', redirectUris: ['http://localhost'], firstParty: true, passwordClient: true);
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->userRepository->method('findByUuid')->willReturn($user);

        return [$client, $user];
    }
}
