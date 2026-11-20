<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimiter;

use Baander\RedisRateLimiter\RateLimiterSnapshot;
use Baander\RedisRateLimiter\RedisRateLimiterFactory;
use Baander\RedisRateLimiter\RedisRateLimiterStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * LRCLIB allows an address it cannot identify about 45 requests a minute, so the shipped
 * `lrclib` limit must start every turn at least two seconds after the one before.
 */
final class LrclibLimiterSpacingTest extends TestCase
{
    /** @var array<string, string> payloads committed to the stand-in store, by limiter and subject */
    private array $payloads = [];

    public function testTurnsReservedInARowComeTwoSecondsApart(): void
    {
        $limiter = new RedisRateLimiterFactory(self::shippedConfig(), $this->workingStore())->create('lrclib.net');

        $first = $limiter->reserve()->getTimeToAct();
        $second = $limiter->reserve()->getTimeToAct();
        $third = $limiter->reserve()->getTimeToAct();

        self::assertGreaterThanOrEqual(2.0, $second - $first);
        self::assertGreaterThanOrEqual(2.0, $third - $second);
    }

    /** @return array<string, mixed> */
    private static function shippedConfig(): array
    {
        $config = Yaml::parseFile(dirname(__DIR__, 5) . '/config/packages/framework.yaml')['framework']['rate_limiter']['lrclib'];

        return ['id' => 'lrclib', ...$config];
    }

    /** A store that keeps committed payloads in this test, standing in for Redis. */
    private function workingStore(): RedisRateLimiterStore
    {
        $store = $this->createStub(RedisRateLimiterStore::class);
        $store->method('read')->willReturnCallback(fn(string $name, string $subject): RateLimiterSnapshot => new RateLimiterSnapshot(
            $name, $subject, str_repeat('a', 32), str_repeat('b', 32), $this->payloads[$name . $subject] ?? '', 1,
        ));
        $store->method('commit')->willReturnCallback(function (RateLimiterSnapshot $snapshot, string $payload): bool {
            $this->payloads[$snapshot->name . $snapshot->subject] = $payload;

            return true;
        });

        return $store;
    }
}
