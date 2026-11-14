<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimiter;

use App\Shared\Infrastructure\RateLimiter\RateLimiterSnapshot;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterFactory;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * MusicBrainz answers 503 to an address that sends more than one request per second, so the
 * shipped `musicbrainz` limit must start every turn at least a second after the one before.
 */
final class MusicBrainzLimiterSpacingTest extends TestCase
{
    /** @var array<string, string> payloads committed to the stand-in store, by limiter and subject */
    private array $payloads = [];

    public function testATurnReservedJustAfterTheFirstTurnsSecondEndsStillWaitsAFullSecondAfterTheTurnBefore(): void
    {
        $limiter = new RedisRateLimiterFactory(self::shippedConfig(), $this->workingStore())->create('musicbrainz.org');

        $first = $limiter->reserve()->getTimeToAct();
        self::sleepUntil($first + 0.3);
        // Its turn comes after the first one's second is over.
        $second = $limiter->reserve()->getTimeToAct();
        self::sleepUntil($first + 1.05);
        // Asked for after the first turn's second has ended, before the second turn comes.
        $third = $limiter->reserve()->getTimeToAct();

        self::assertGreaterThanOrEqual(1.0, $second - $first);
        self::assertGreaterThanOrEqual(1.0, $third - $second);
    }

    /** @return array<string, mixed> */
    private static function shippedConfig(): array
    {
        $config = Yaml::parseFile(dirname(__DIR__, 5) . '/config/packages/framework.yaml')['framework']['rate_limiter']['musicbrainz'];

        return ['id' => 'musicbrainz', ...$config];
    }

    private static function sleepUntil(float $moment): void
    {
        $seconds = $moment - microtime(true);
        if ($seconds > 0) {
            usleep((int) ($seconds * 1e6));
        }
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
