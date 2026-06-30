<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\OAuth\AuthorizationServerFactory;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthorizationServerFactoryTest extends TestCase
{
    private ClientRepositoryInterface&MockObject $clientRepository;
    private AccessTokenRepositoryInterface&MockObject $accessTokenRepository;
    private ScopeRepositoryInterface&MockObject $scopeRepository;
    private AuthCodeRepositoryInterface&MockObject $authCodeRepository;
    private RefreshTokenRepositoryInterface&MockObject $refreshTokenRepository;
    private DeviceCodeRepositoryInterface&MockObject $deviceCodeRepository;

    private static string $privateKeyPath;
    private static bool $keyCreatedByUs = false;

    public static function setUpBeforeClass(): void
    {
        $realKeyPath = dirname(__DIR__, 5).'/config/secrets/oauth/private.key';

        if (file_exists($realKeyPath) && filesize($realKeyPath) > 0) {
            self::$privateKeyPath = $realKeyPath;
        } else {
            $tempDir = sys_get_temp_dir();
            self::$privateKeyPath = $tempDir.'/test_oauth_private_'.uniqid().'.key';
            $key = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            openssl_pkey_export_to_file($key, self::$privateKeyPath);
            chmod(self::$privateKeyPath, 0600);
            self::$keyCreatedByUs = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$keyCreatedByUs && file_exists(self::$privateKeyPath)) {
            unlink(self::$privateKeyPath);
        }
    }

    protected function setUp(): void
    {
        $this->clientRepository = $this->createMock(ClientRepositoryInterface::class);
        $this->accessTokenRepository = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->scopeRepository = $this->createMock(ScopeRepositoryInterface::class);
        $this->authCodeRepository = $this->createMock(AuthCodeRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepositoryInterface::class);
        $this->deviceCodeRepository = $this->createMock(DeviceCodeRepositoryInterface::class);
    }

    private function createFactory(string $encryptionKey = ''): AuthorizationServerFactory
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
        $_SERVER['APP_ENV'] = 'prod';
        try {
            $factory = $this->createFactory('');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('AUTH_ENCRYPTION_KEY must be configured in production');

            $factory->create();
        } finally {
            unset($_SERVER['APP_ENV']);
        }
    }

    public function testCreateWithEmptyKeyInDevDoesNotThrow(): void
    {
        $_SERVER['APP_ENV'] = 'dev';
        try {
            $factory = $this->createFactory('');

            // In dev, empty key should not throw (random key generated)
            $server = $factory->create();

            $this->assertInstanceOf(\League\OAuth2\Server\AuthorizationServer::class, $server);
        } finally {
            unset($_SERVER['APP_ENV']);
        }
    }

    public function testCreateWithEmptyKeyInTestDoesNotThrow(): void
    {
        $_SERVER['APP_ENV'] = 'test';
        try {
            $factory = $this->createFactory('');

            $server = $factory->create();

            $this->assertInstanceOf(\League\OAuth2\Server\AuthorizationServer::class, $server);
        } finally {
            unset($_SERVER['APP_ENV']);
        }
    }

    public function testCreateWithEmptyKeyInDevTriggersDeprecation(): void
    {
        $_SERVER['APP_ENV'] = 'dev';
        set_error_handler(static function (int $errno, string $errstr): bool {
            self::assertSame(E_USER_DEPRECATED, $errno);
            self::assertStringContainsString('No AUTH_ENCRYPTION_KEY is configured', $errstr);

            return true;
        });

        try {
            $factory = $this->createFactory('');
            $factory->create();
        } finally {
            restore_error_handler();
            unset($_SERVER['APP_ENV']);
        }
    }
}
