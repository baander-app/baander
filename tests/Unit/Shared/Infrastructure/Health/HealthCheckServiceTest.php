<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\Health\HealthCheckResult;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthStatus;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class HealthCheckServiceTest extends TestCase
{
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
            $service = $this->service(array_fill_keys(['Discogs', 'Last.fm', 'Spotify'], $key));
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
    private function service(array $apiKeys = []): HealthCheckService
    {
        return new HealthCheckService(
            connection: $this->createStub(Connection::class),
            redisClientFactory: $this->createStub(RedisClientFactory::class),
            appEnv: 'prod',
            appSecret: str_repeat('secure-', 6),
            appUrl: 'https://baander.app',
            oauthPrivateKeyPath: '/missing-baander-oauth/private.key',
            oauthPublicKeyPath: '/missing-baander-oauth/public.key',
            vapidPublicKey: '',
            vapidPrivateKey: '',
            messengerWorkerHealth: new MessengerWorkerHealth($this->createStub(ClockInterface::class)),
            apiKeys: $apiKeys,
        );
    }
}
