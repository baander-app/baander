<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\Health\HealthCheckResult;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthStatus;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class HealthCheckServiceTest extends TestCase
{
    public function testValidInjectedOAuthKeyDoesNotRequireGetenv(): void
    {
        $previous = getenv('OAUTH_ENCRYPTION_KEY');
        putenv('OAUTH_ENCRYPTION_KEY');
        try {
            self::assertFalse(getenv('OAUTH_ENCRYPTION_KEY'));
            $results = $this->service(Key::createNewRandomKey()->saveToAsciiSafeString())->checkConfiguration();
            self::assertSame(HealthStatus::Healthy, $this->oauthResult($results)->status);
            foreach ($results as $result) {
                self::assertNotSame('OAUTH_ENCRYPTION_KEY', $result->details['var'] ?? null);
            }
        } finally {
            putenv($previous === false ? 'OAUTH_ENCRYPTION_KEY' : 'OAUTH_ENCRYPTION_KEY=' . $previous);
        }
    }

    public function testMissingInjectedProductionKeyRemainsUnhealthy(): void
    {
        $result = $this->oauthResult($this->service('')->checkConfiguration());
        self::assertSame(HealthStatus::Unhealthy, $result->status);
        self::assertStringContainsString('OAUTH_ENCRYPTION_KEY', $result->details['message']);
        self::assertStringContainsString('Key::createNewRandomKey()', $result->details['suggestion']);
        self::assertStringContainsString('require "vendor/autoload.php";', $result->details['suggestion']);
        self::assertStringContainsString('environment provider', $result->details['suggestion']);
    }

    public function testInvalidInjectedProductionKeyIsRejectedWithoutLeakingValue(): void
    {
        $result = $this->oauthResult($this->service('unique-private-invalid-key')->checkConfiguration());
        self::assertSame(HealthStatus::Unhealthy, $result->status);
        self::assertStringContainsString('OAUTH_ENCRYPTION_KEY', $result->details['suggestion']);
        self::assertStringNotContainsString('unique-private-invalid-key', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    #[DataProvider('externalKeyValues')]
    public function testExternalKeysUseInjectedConfigurationWithoutGetenv(string $key, int $expectedWarnings): void
    {
        $variables = ['DISCOGS_TOKEN', 'LASTFM_API_KEY', 'SPOTIFY_CLIENT_ID'];
        $previous = [];
        foreach ($variables as $variable) {
            $previous[$variable] = getenv($variable);
            putenv($variable);
        }
        try {
            foreach ($variables as $variable) {
                self::assertFalse(getenv($variable));
            }
            $service = $this->service('', array_fill_keys(['Discogs', 'Last.fm', 'Spotify'], $key));
            $results = array_values(array_filter($service->checkConfiguration(), static fn (HealthCheckResult $result): bool => $result->component === 'api_keys'));
            self::assertCount($expectedWarnings, $results);
            foreach ($results as $index => $result) {
                self::assertSame(HealthStatus::NotAvailable, $result->status);
                self::assertSame($variables[$index], $result->details['var']);
                self::assertStringNotContainsString($key, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
            }
        } finally {
            foreach ($previous as $variable => $value) {
                putenv($value === false ? $variable : $variable . '=' . $value);
            }
        }
    }

    /** @return iterable<string,array{string,int}> */
    public static function externalKeyValues(): iterable
    {
        yield 'short injected keys warn' => ['tiny', 3];
        yield 'valid injected keys pass' => ['valid-injected-api-key', 0];
        yield 'empty injected keys remain optional' => ['', 0];
    }

    /** @param array<string,string> $apiKeys */
    private function service(string $key, array $apiKeys = []): HealthCheckService
    {
        return new HealthCheckService(
            connection: $this->createStub(Connection::class),
            redisClientFactory: $this->createStub(RedisClientFactory::class),
            appEnv: 'prod',
            appSecret: str_repeat('secure-', 6),
            appUrl: 'https://baander.app',
            oauthEncryptionKey: $key,
            oauthPrivateKeyPath: '/missing-baander-oauth/private.key',
            oauthPublicKeyPath: '/missing-baander-oauth/public.key',
            vapidPublicKey: '',
            vapidPrivateKey: '',
            messengerWorkerHealth: new MessengerWorkerHealth($this->createStub(ClockInterface::class)),
            apiKeys: $apiKeys,
        );
    }

    /** @param list<HealthCheckResult> $results */
    private function oauthResult(array $results): HealthCheckResult
    {
        $matches = array_values(array_filter($results, static fn (HealthCheckResult $result): bool => $result->component === 'oauth_encryption_key'));
        self::assertCount(1, $matches);

        return $matches[0];
    }
}
