<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\CommandHandler\OAuth\IssueTokenHandler;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use App\Shared\Domain\Model\Email;
use App\Auth\Domain\Model\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Validation test: PKCE code_verifier must be verified during the
 * authorization_code grant. The current handler ignores the verifier,
 * so these tests fail until PKCE verification is implemented.
 */
final class IssueTokenHandlerPkceTest extends TestCase
{
    private AccessTokenRepositoryInterface&Stub $accessTokenRepository;
    private RefreshTokenRepositoryInterface&Stub $refreshTokenRepository;
    private AuthCodeRepositoryInterface&Stub $authCodeRepository;
    private DeviceCodeRepositoryInterface&Stub $deviceCodeRepository;
    private ClientRepositoryInterface&Stub $clientRepository;
    private UserRepositoryInterface&Stub $userRepository;
    private EntityManagerInterface&Stub $entityManager;
    private IssueTokenHandler $handler;

    protected function setUp(): void
    {
        $this->accessTokenRepository = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createStub(RefreshTokenRepositoryInterface::class);
        $this->authCodeRepository = $this->createStub(AuthCodeRepositoryInterface::class);
        $this->deviceCodeRepository = $this->createStub(DeviceCodeRepositoryInterface::class);
        $this->clientRepository = $this->createStub(ClientRepositoryInterface::class);
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);

        $chainValidatorAccessTokenRepo = $this->createStub(AccessTokenRepositoryInterface::class);
        $chainValidatorRefreshTokenRepo = $this->createStub(RefreshTokenRepositoryInterface::class);
        $chainValidator = new TokenChainValidator(
            $chainValidatorAccessTokenRepo,
            $chainValidatorRefreshTokenRepo,
        );

        $scopeAllowlist = new ScopeAllowlist(
            userGrants: ['profile', 'email', 'library', 'playlist'],
            clientCredentials: ['admin'],
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn(callable $callback) => $callback());
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $jwtGenerator = $this->createStub(JwtGeneratorInterface::class);
        $jwtGenerator->method('generate')->willReturn('mock-jwt');

        $tokenMetadataRepository = $this->createStub(TokenMetadataRepositoryInterface::class);

        $this->handler = new IssueTokenHandler(
            $this->accessTokenRepository,
            $this->refreshTokenRepository,
            $this->authCodeRepository,
            $this->deviceCodeRepository,
            $this->clientRepository,
            $this->userRepository,
            $chainValidator,
            $scopeAllowlist,
            $this->entityManager,
            $jwtGenerator,
            $tokenMetadataRepository,
            accessTokenTtl: 3600,
            refreshTokenTtl: 2592000,
        );
    }

    public function testAuthorizationCodeWithMismatchedCodeVerifierIsRejected(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $authCode = AuthCode::create(
            $user,
            $client,
            [new Scope('profile')],
            codeChallenge: '7EKiB4CKAqNtAM11sZLpgZ9wDkAtUHMzGZffUz34FtY',
            codeChallengeMethod: 'S256',
        );

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->authCodeRepository->method('findByCodeId')->willReturn($authCode);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            code: $authCode->getCodeId()->toString(),
            codeVerifier: 'definitely-not-the-right-verifier',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('code_verifier');

        ($this->handler)($command);
    }

    public function testAuthorizationCodeWithoutCodeVerifierWhenPkceRequiredIsRejected(): void
    {
        $user = User::register(new Email('user@example.com'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $authCode = AuthCode::create(
            $user,
            $client,
            [new Scope('profile')],
            codeChallenge: '7EKiB4CKAqNtAM11sZLpgZ9wDkAtUHMzGZffUz34FtY',
            codeChallengeMethod: 'S256',
        );

        $this->clientRepository->method('findClientByUuid')->willReturn($client);
        $this->authCodeRepository->method('findByCodeId')->willReturn($authCode);

        $command = new IssueTokenCommand(
            grantType: 'authorization_code',
            clientId: $client->getId(),
            clientSecret: $client->getSecret(),
            code: $authCode->getCodeId()->toString(),
            // codeVerifier intentionally omitted
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('code_verifier');

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
