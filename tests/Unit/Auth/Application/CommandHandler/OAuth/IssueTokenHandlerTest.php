<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\CommandHandler\OAuth\IssueTokenHandler;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\ScopeAllowlist;
use InvalidArgumentException;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\AuthCodeState;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\DeviceCodeState;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\RefreshTokenState;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use App\Shared\Domain\Model\Email;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IssueTokenHandlerTest extends TestCase
{
    private AccessTokenRepositoryInterface&MockObject $accessTokenRepository;
    private RefreshTokenRepositoryInterface&MockObject $refreshTokenRepository;
    private AuthCodeRepositoryInterface&MockObject $authCodeRepository;
    private DeviceCodeRepositoryInterface&MockObject $deviceCodeRepository;
    private ClientRepositoryInterface&MockObject $clientRepository;
    private UserRepositoryInterface&MockObject $userRepository;
    private ScopeAllowlist $scopeAllowlist;
    private EntityManagerInterface&MockObject $entityManager;
    private IssueTokenHandler $handler;

    protected function setUp(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->authCodeRepository = $this->createMock(AuthCodeRepositoryInterface::class);
        $this->deviceCodeRepository = $this->createMock(DeviceCodeRepositoryInterface::class);
        $this->clientRepository = $this->createMock(ClientRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->scopeAllowlist = new ScopeAllowlist(
            userGrants: ['profile', 'email', 'library', 'playlist'],
            clientCredentials: ['admin'],
        );

        // TokenChainValidator is final and cannot be mocked. Use a real instance
        // with dedicated repository mocks for its dependency chain.
        $chainValidatorAccessTokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $chainValidatorRefreshTokenRepo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $chainValidator = new TokenChainValidator(
            $chainValidatorAccessTokenRepo,
            $chainValidatorRefreshTokenRepo,
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn(callable $callback) => $callback());
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $jwtGenerator = $this->createMock(JwtGeneratorInterface::class);
        $jwtGenerator->method('generate')->willReturn('eyJhbGciOiJSUzI1NiJ9.eyJqdGkiOiJ0ZXN0In0.signature');

        $this->handler = new IssueTokenHandler(
            $this->accessTokenRepository,
            $this->refreshTokenRepository,
            $this->authCodeRepository,
            $this->deviceCodeRepository,
            $this->clientRepository,
            $this->userRepository,
            $chainValidator,
            $this->scopeAllowlist,
            $this->entityManager,
            $jwtGenerator,
            accessTokenTtl: 3600,
            refreshTokenTtl: 2592000,
        );
    }

    // --- Scope filtering tests ---

    public function testAuthorizationCodeWithStandardScopesSucceeds(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $authCode = AuthCode::create(
            $user,
            $client,
            [new Scope('profile'), new Scope('email')],
        );

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->authCodeRepository->method('findByCodeId')->willReturn($authCode);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            scopes: ['profile', 'email'],
            code: $authCode->getCodeId()->toString(),
        );

        $result = ($this->handler)($command);

        $this->assertContains('profile', $result->getScopes());
        $this->assertContains('email', $result->getScopes());
        $this->assertNotEmpty($result->getAccessToken());
        $this->assertNotNull($result->getRefreshToken());
    }

    public function testAuthorizationCodeDropsAdminScope(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $authCode = AuthCode::create($user, $client);

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->authCodeRepository->method('findByCodeId')->willReturn($authCode);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            scopes: ['profile', 'admin', 'email'],
            code: $authCode->getCodeId()->toString(),
        );

        $result = ($this->handler)($command);

        $this->assertContains('profile', $result->getScopes());
        $this->assertContains('email', $result->getScopes());
        $this->assertNotContains('admin', $result->getScopes());
    }

    public function testAuthorizationCodeFiltersMixedValidAndInvalidScopes(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $authCode = AuthCode::create(
            $user,
            $client,
            [new Scope('library')],
        );

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->authCodeRepository->method('findByCodeId')->willReturn($authCode);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            scopes: ['playlist', 'admin', 'superuser', 'nonexistent'],
            code: $authCode->getCodeId()->toString(),
        );

        $result = ($this->handler)($command);

        $this->assertContains('playlist', $result->getScopes());
        // 'library' should come from existing scopes on the auth code
        $this->assertContains('library', $result->getScopes());
        $this->assertNotContains('admin', $result->getScopes());
        $this->assertNotContains('superuser', $result->getScopes());
        $this->assertNotContains('nonexistent', $result->getScopes());
    }

    public function testClientCredentialsWithAdminScopeSucceeds(): void
    {
        $client = $this->createConfidentialClient();

        $this->clientRepository->method('findClientByUuid')->willReturn($client);

        $command = new IssueTokenCommand(
            grantType: 'client_credentials',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            scopes: ['admin'],
        );

        $result = ($this->handler)($command);

        $this->assertContains('admin', $result->getScopes());
        $this->assertNotEmpty($result->getAccessToken());
    }

    public function testClientCredentialsDropsUserScopes(): void
    {
        $client = $this->createConfidentialClient();

        $this->clientRepository->method('findClientByUuid')->willReturn($client);

        $command = new IssueTokenCommand(
            grantType: 'client_credentials',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            scopes: ['admin', 'profile', 'email'],
        );

        $result = ($this->handler)($command);

        $this->assertContains('admin', $result->getScopes());
        $this->assertNotContains('profile', $result->getScopes());
        $this->assertNotContains('email', $result->getScopes());
    }

    // --- Direct grant validation ---

    public function testDirectGrantRequiresClientId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('clientId');

        $command = new IssueTokenCommand(grantType: 'direct_grant');
        ($this->handler)($command);
    }

    // --- Authorization code edge cases ---

    public function testAuthorizationCodeWithInvalidCodeThrows(): void
    {
        $client = $this->createConfidentialClient();

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->authCodeRepository->method('findByCodeId')->willReturn(null);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            code: str_repeat('x', 80),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authorization code not found');

        ($this->handler)($command);
    }

    public function testAuthorizationCodeWithRevokedCodeThrows(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $authCode = AuthCode::create($user, $client);
        $authCode->revoke();

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->authCodeRepository->method('findByCodeId')->willReturn($authCode);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            code: $authCode->getCodeId()->toString(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authorization code has been revoked');

        ($this->handler)($command);
    }

    public function testAuthorizationCodeWithExpiredCodeThrows(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();

        // Create an expired auth code via reconstitute
        $authCode = AuthCode::reconstitute(new AuthCodeState(
            id: \App\Shared\Domain\Model\Uuid::generate(),
            codeId: \App\Auth\Domain\Model\OAuth\TokenId::generate(),
            user: $user,
            client: $client,
            scopes: [],
            expiresAt: new \DateTimeImmutable('-1 hour'),
            createdAt: new \DateTimeImmutable('-2 hours'),
            updatedAt: new \DateTimeImmutable('-2 hours'),
            revoked: false,
        ));

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->authCodeRepository->method('findByCodeId')->willReturn($authCode);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            code: $authCode->getCodeId()->toString(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authorization code has expired');

        ($this->handler)($command);
    }

    // --- Client credentials edge cases ---

    public function testClientCredentialsRequiresConfidentialClient(): void
    {
        $publicClient = Client::create(
            name: 'Public App',
            redirectUris: ['http://localhost'],
            confidential: false,
        );

        $this->clientRepository->method('findClientByUuid')->willReturn($publicClient);

        $command = new IssueTokenCommand(
            grantType: 'client_credentials',
            clientId: $publicClient->getId(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('confidential client');

        ($this->handler)($command);
    }

    // --- Confidential client auth bypass fix ---

    public function testConfidentialClientWithOmittedSecretIsRejected(): void
    {
        $client = $this->createConfidentialClient();

        $this->clientRepository->method('findClientByUuid')->willReturn($client);

        $command = new IssueTokenCommand(
            grantType: 'client_credentials',
            clientId: $client->getId(),
            clientSecret: null,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client secret is required for confidential clients');

        ($this->handler)($command);
    }

    public function testConfidentialClientWithWrongSecretIsRejected(): void
    {
        $client = $this->createConfidentialClient();

        $this->clientRepository->method('findClientByUuid')->willReturn($client);

        $command = new IssueTokenCommand(
            grantType: 'client_credentials',
            clientId: $client->getId(),
            clientSecret: 'wrong-secret',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid client credentials');

        ($this->handler)($command);
    }

    public function testPublicClientWithoutSecretSucceeds(): void
    {
        $publicClient = Client::create(
            name: 'Public App',
            redirectUris: ['http://localhost'],
            confidential: false,
        );

        $this->clientRepository->method('findClientByUuid')->willReturn($publicClient);

        $command = new IssueTokenCommand(
            grantType: 'client_credentials',
            clientId: $publicClient->getId(),
        );

        // Should throw because public clients can't use client_credentials grant,
        // NOT because of missing secret
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('confidential client');

        ($this->handler)($command);
    }

    // --- Refresh token edge cases (within IssueTokenHandler) ---

    public function testRefreshTokenWithExpiredTokenThrows(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $accessToken = \App\Auth\Domain\Model\OAuth\AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
        );

        $refreshToken = RefreshToken::reconstitute(new RefreshTokenState(
            id: \App\Shared\Domain\Model\Uuid::generate(),
            tokenId: \App\Auth\Domain\Model\OAuth\TokenId::generate(),
            accessToken: $accessToken,
            chainId: $chainId,
            previousRefreshToken: null,
            expiresAt: new \DateTimeImmutable('-1 hour'),
            usedAt: null,
            createdAt: new \DateTimeImmutable('-1 hour'),
            updatedAt: new \DateTimeImmutable('-1 hour'),
            revoked: false,
        ));

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->refreshTokenRepository->method('findByTokenId')->willReturn($refreshToken);

        $command = new IssueTokenCommand(
            grantType: 'refresh_token',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            code: $refreshToken->getTokenId()->toString(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired');

        ($this->handler)($command);
    }

    public function testRefreshTokenWithRevokedTokenThrows(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();

        $accessToken = \App\Auth\Domain\Model\OAuth\AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            null,
        );

        $refreshToken = \App\Auth\Domain\Model\OAuth\RefreshToken::issue(
            $accessToken,
            null,
            null,
        );
        $refreshToken->revoke();

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->refreshTokenRepository->method('findByTokenId')->willReturn($refreshToken);

        $command = new IssueTokenCommand(
            grantType: 'refresh_token',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            code: $refreshToken->getTokenId()->toString(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('revoked');

        ($this->handler)($command);
    }

    public function testRefreshTokenWithUsedTokenThrows(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $accessToken = \App\Auth\Domain\Model\OAuth\AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
        );

        // Reconstitute with usedAt set to simulate a previously-used refresh token
        $refreshToken = RefreshToken::reconstitute(new RefreshTokenState(
            id: \App\Shared\Domain\Model\Uuid::generate(),
            tokenId: \App\Auth\Domain\Model\OAuth\TokenId::generate(),
            accessToken: $accessToken,
            chainId: $chainId,
            previousRefreshToken: null, // no previous
            expiresAt: null, // no expiry
            usedAt: new \DateTimeImmutable('-5 minutes'), // used 5 minutes ago
            createdAt: new \DateTimeImmutable('-5 minutes'),
            updatedAt: new \DateTimeImmutable('-5 minutes'),
            revoked: false,
        ));

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->refreshTokenRepository->method('findByTokenId')->willReturn($refreshToken);

        $command = new IssueTokenCommand(
            grantType: 'refresh_token',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            code: $refreshToken->getTokenId()->toString(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reuse detected');

        ($this->handler)($command);
    }

    // --- Unsupported grant type ---

    public function testUnsupportedGrantTypeThrows(): void
    {
        $command = new IssueTokenCommand(grantType: 'unsupported_grant');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported grant type');

        ($this->handler)($command);
    }

    // --- Device code edge cases ---

    public function testDeviceCodeWithExpiredCodeThrows(): void
    {
        $client = $this->createConfidentialClient();

        $deviceCode = DeviceCode::reconstitute(new DeviceCodeState(
            id: \App\Shared\Domain\Model\Uuid::generate(),
            deviceCode: \App\Auth\Domain\Model\OAuth\TokenId::generate(),
            userCode: 'ABCD-EFGH',
            user: null,
            client: $client,
            scopes: [],
            verificationUri: 'http://localhost/verify',
            verificationUriComplete: null,
            expiresAt: new \DateTimeImmutable('-1 hour'), // expired
            interval: 5,
            lastPolledAt: null,
            createdAt: new \DateTimeImmutable('-1 hour'),
            updatedAt: new \DateTimeImmutable('-1 hour'),
            approved: false,
            denied: false,
            consumedAt: null,
        ));

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->deviceCodeRepository->method('findByDeviceCode')->willReturn($deviceCode);

        $command = new IssueTokenCommand(
            grantType: 'urn:ietf:params:oauth:grant-type:device_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            deviceCode: str_repeat('d', 80),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired');

        ($this->handler)($command);
    }

    public function testDeviceCodeWithDeniedCodeThrows(): void
    {
        $client = $this->createConfidentialClient();

        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $deviceCode = DeviceCode::create($client, 'ABCD-EFGH', 'http://localhost/verify');
        $deviceCode->deny();

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->deviceCodeRepository->method('findByDeviceCode')->willReturn($deviceCode);

        $command = new IssueTokenCommand(
            grantType: 'urn:ietf:params:oauth:grant-type:device_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            deviceCode: $deviceCode->getDeviceCode()->toString(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('denied');

        ($this->handler)($command);
    }

    public function testDeviceCodeWithConsumedCodeThrows(): void
    {
        $client = $this->createConfidentialClient();
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');

        $deviceCode = DeviceCode::create($client, 'ABCD-EFGH', 'http://localhost/verify');
        $deviceCode->approve($user);
        $deviceCode->consume();

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->deviceCodeRepository->method('findByDeviceCode')->willReturn($deviceCode);

        $command = new IssueTokenCommand(
            grantType: 'urn:ietf:params:oauth:grant-type:device_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            deviceCode: $deviceCode->getDeviceCode()->toString(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('consumed');

        ($this->handler)($command);
    }

    public function testDeviceCodeWithPendingCodeReturnsSlowPolling(): void
    {
        $client = $this->createConfidentialClient();

        $deviceCode = DeviceCode::create(
            $client,
            'ABCD-EFGH',
            'http://localhost/verify',
            null,
            [new Scope('profile')],
            null,
            5,
        );

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->deviceCodeRepository->method('findByDeviceCode')->willReturn($deviceCode);

        $command = new IssueTokenCommand(
            grantType: 'urn:ietf:params:oauth:grant-type:device_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            deviceCode: $deviceCode->getDeviceCode()->toString(),
        );

        $result = ($this->handler)($command);

        $this->assertEmpty($result->getAccessToken());
        $this->assertEquals(0, $result->getExpiresIn());
        $this->assertEquals('ABCD-EFGH', $result->getDeviceId());
        $this->assertEquals(5, $result->getVerificationInterval());
    }

    public function testDeviceCodeWithApprovedCodeReturnsTokenPair(): void
    {
        $client = $this->createConfidentialClient();
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');

        $deviceCode = DeviceCode::create(
            $client,
            'ABCD-EFGH',
            'http://localhost/verify',
            null,
            [new Scope('profile')],
            null,
            5,
        );
        $deviceCode->approve($user);

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->deviceCodeRepository->method('findByDeviceCode')->willReturn($deviceCode);

        $command = new IssueTokenCommand(
            grantType: 'urn:ietf:params:oauth:grant-type:device_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            deviceCode: $deviceCode->getDeviceCode()->toString(),
        );

        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
        $this->assertNotNull($result->getRefreshToken());
        $this->assertContains('profile', $result->getScopes());
    }

    // --- Client not found ---

    public function testClientNotFoundThrows(): void
    {
        $this->clientRepository->method('findClientByUuid')->willReturn(null);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: \App\Shared\Domain\Model\Uuid::generate(),
            code: str_repeat('c', 80),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client not found');

        ($this->handler)($command);
    }

    public function testRevokedClientThrows(): void
    {
        $client = $this->createConfidentialClient();
        $client->revoke();

        $this->clientRepository->method('findClientByUuid')->willReturn($client);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            code: str_repeat('c', 80),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client has been revoked');

        ($this->handler)($command);
    }

    // --- Redirect URI validation ---

    public function testAuthorizationCodeWithDisallowedRedirectUriThrows(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $authCode = AuthCode::create($user, $client);

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->authCodeRepository->method('findByCodeId')->willReturn($authCode);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            code: $authCode->getCodeId()->toString(),
            redirectUri: 'http://evil.com/callback',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redirect URI is not allowed');

        ($this->handler)($command);
    }

    public function testAuthorizationCodeWithAllowedRedirectUriSucceeds(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $authCode = AuthCode::create(
            $user,
            $client,
            [new Scope('profile')],
        );

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->authCodeRepository->method('findByCodeId')->willReturn($authCode);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            scopes: ['profile'],
            code: $authCode->getCodeId()->toString(),
            redirectUri: 'http://localhost',
        );

        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
        $this->assertNotNull($result->getRefreshToken());
    }

    // --- Token name is preserved ---

    public function testAuthorizationCodePreservesTokenName(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $authCode = AuthCode::create(
            $user,
            $client,
            [new Scope('profile')],
        );

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->authCodeRepository->method('findByCodeId')->willReturn($authCode);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            scopes: ['profile'],
            code: $authCode->getCodeId()->toString(),
            tokenName: 'My Laptop',
        );

        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
        $this->assertNotNull($result->getRefreshToken());
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
