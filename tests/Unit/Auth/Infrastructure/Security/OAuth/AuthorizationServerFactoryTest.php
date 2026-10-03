<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\OAuth\AuthorizationServerFactory;
use App\Auth\Infrastructure\Security\OAuth\TransactionalAuthorizationServer;
use App\Shared\Application\Port\TransactionPortInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthorizationServerFactoryTest extends TestCase
{
    private ClientRepositoryInterface&Stub $clientRepository;
    private AccessTokenRepositoryInterface&Stub $accessTokenRepository;
    private ScopeRepositoryInterface&Stub $scopeRepository;
    private AuthCodeRepositoryInterface&Stub $authCodeRepository;
    private RefreshTokenRepositoryInterface&Stub $refreshTokenRepository;
    private DeviceCodeRepositoryInterface&Stub $deviceCodeRepository;

    private static string $privateKeyPath;
    private static bool $keyCreatedByUs = false;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $path = tempnam(sys_get_temp_dir(), 'baander-oauth-');
        if ($key === false || $path === false) {
            throw new RuntimeException('Cannot create disposable OAuth key fixture.');
        }
        if (!openssl_pkey_export_to_file($key, $path) || !chmod($path, 0600)) {
            unlink($path);
            throw new RuntimeException('Cannot write disposable OAuth key fixture.');
        }
        self::$privateKeyPath = $path;
        self::$keyCreatedByUs = true;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$keyCreatedByUs && file_exists(self::$privateKeyPath)) {
            unlink(self::$privateKeyPath);
        }
    }

    protected function setUp(): void
    {
        $this->clientRepository = $this->createStub(ClientRepositoryInterface::class);
        $this->accessTokenRepository = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->scopeRepository = $this->createStub(ScopeRepositoryInterface::class);
        $this->authCodeRepository = $this->createStub(AuthCodeRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createStub(RefreshTokenRepositoryInterface::class);
        $this->deviceCodeRepository = $this->createStub(DeviceCodeRepositoryInterface::class);
    }

    private function createFactory(string $encryptionKey = '', string $environment = 'prod', ?TransactionPortInterface $transaction = null, ?EntityManagerInterface $entityManager = null): AuthorizationServerFactory
    {
        return new AuthorizationServerFactory(
            clientRepository: $this->clientRepository,
            accessTokenRepository: $this->accessTokenRepository,
            scopeRepository: $this->scopeRepository,
            authCodeRepository: $this->authCodeRepository,
            refreshTokenRepository: $this->refreshTokenRepository,
            deviceCodeRepository: $this->deviceCodeRepository,
            privateKeyPath: self::$privateKeyPath,
            encryptionKey: $encryptionKey,
            verificationUri: '/device/verify',
            transaction: $transaction ?? $this->createStub(TransactionPortInterface::class),
            entityManager: $entityManager ?? $this->createStub(EntityManagerInterface::class),
            environment: $environment,
        );
    }

    public function testCreateWithValidEncryptionKeyReturnsServer(): void
    {
        $key = \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();
        $factory = $this->createFactory($key);

        $server = $factory->create();

        $this->assertInstanceOf(\League\OAuth2\Server\AuthorizationServer::class, $server);
    }

    public function testCreateWithEmptyKeyInProdThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OAUTH_ENCRYPTION_KEY must be configured in production');
        $this->createFactory()->create();
    }

    public function testCreateWithEmptyKeyInDevDoesNotThrow(): void
    {
        self::assertInstanceOf(\League\OAuth2\Server\AuthorizationServer::class,
            $this->createFactory(environment: 'dev')->create());
    }

    public function testCreateWithEmptyKeyInTestDoesNotThrow(): void
    {
        self::assertInstanceOf(\League\OAuth2\Server\AuthorizationServer::class,
            $this->createFactory(environment: 'test')->create());
    }

    public function testCreateWithEmptyKeyInDevTriggersDeprecation(): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            self::assertSame(E_USER_DEPRECATED, $errno);
            self::assertStringContainsString('No OAUTH_ENCRYPTION_KEY is configured', $errstr);
            return true;
        });
        try {
            $this->createFactory(environment: 'dev')->create();
        } finally {
            restore_error_handler();
        }
    }

    public function testMalformedKeyFailsWithoutExposingSecretOrParserException(): void
    {
        $secret = 'invalid-secret-baander.app';
        try {
            $this->createFactory($secret)->create();
            self::fail('Malformed encryption key must fail.');
        } catch (RuntimeException $error) {
            self::assertSame('OAUTH_ENCRYPTION_KEY must be a valid Defuse ASCII-safe key.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString($secret, $error->getMessage());
        }
    }

    public function testRefreshRejectsNestedTransactionWithoutChangingCallerState(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->beginTransaction();
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $transaction = $this->createMock(TransactionPortInterface::class);
        $transaction->expects(self::never())->method('run');
        $key = \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();
        $server = $this->createFactory($key, transaction: $transaction, entityManager: $manager)->create();
        self::assertInstanceOf(TransactionalAuthorizationServer::class, $server);
        try {
            $server->respondToAccessTokenRequest(
                (new ServerRequest('POST', 'https://baander.app/api/oauth/token'))->withParsedBody(['grant_type' => 'refresh_token']),
                new Response(),
            );
            self::fail('A caller transaction must not contain refresh issuance.');
        } catch (LogicException $error) {
            self::assertSame('Refresh token issuance requires a top-level transaction.', $error->getMessage());
            self::assertSame(1, $connection->getTransactionNestingLevel());
            self::assertTrue($connection->isConnected());
        } finally {
            $connection->rollBack();
            $connection->close();
        }
    }

    public function testNonRefreshRequestDoesNotEnterRefreshTransaction(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getConnection');
        $transaction = $this->createMock(TransactionPortInterface::class);
        $transaction->expects(self::never())->method('run');
        $key = \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();
        $server = $this->createFactory($key, transaction: $transaction, entityManager: $manager)->create();
        $this->expectException(OAuthServerException::class);
        $server->respondToAccessTokenRequest(
            (new ServerRequest('POST', 'https://baander.app/api/oauth/token'))->withParsedBody(['grant_type' => 'unsupported']),
            new Response(),
        );
    }

}
