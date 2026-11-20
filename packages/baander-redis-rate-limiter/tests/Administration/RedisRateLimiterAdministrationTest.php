<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter\Tests\Administration;

use Baander\RedisRateLimiter\Exception\RateLimiterClearFailedException;
use Baander\RedisRateLimiter\Exception\UnknownRateLimiterException;
use Baander\RedisRateLimiter\Administration\RedisRateLimiterAdministration;
use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;

final class RedisRateLimiterAdministrationTest extends TestCase
{
    public function testClearAllContinuesAfterFailureAndReportsOnlyFailedNames(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::exactly(2))->method('set')->willReturnCallback(static function (string $key): bool {
            if (str_starts_with($key, RedisRateLimiterStore::namespace('broken'))) {
                throw new RedisException('private endpoint and subject');
            }
            return true;
        });
        $administration = $this->administration($redis);
        try {
            $administration->clearAll();
            self::fail('Expected the failed namespace to be reported.');
        } catch (RateLimiterClearFailedException $exception) {
            self::assertStringContainsString('broken', $exception->getMessage());
            self::assertStringNotContainsString('healthy', $exception->getMessage());
            self::assertStringNotContainsString('private', $exception->getMessage());
        }
    }

    public function testRefusedWriteIsNotReportedAsASuccessfulClear(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('set')->willReturn(false);
        $this->expectException(RateLimiterClearFailedException::class);
        $this->administration($redis)->clear('healthy');
    }

    public function testUnknownNameDoesNotTouchRedis(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::never())->method('set');
        $this->expectException(UnknownRateLimiterException::class);
        $this->administration($redis)->clear('unknown');
    }

    private function administration(Redis $redis): RedisRateLimiterAdministration
    {
        $definitions = [];
        foreach (['broken', 'healthy'] as $name) {
            $definitions[$name] = ['policy' => 'fixed_window', 'limit' => 10, 'interval' => '60 seconds', 'description' => null, 'storageNamespace' => RedisRateLimiterStore::namespace($name)];
        }
        return new RedisRateLimiterAdministration($definitions, new RedisRateLimiterStore(static fn(callable $operation): mixed => $operation($redis)));
    }
}
