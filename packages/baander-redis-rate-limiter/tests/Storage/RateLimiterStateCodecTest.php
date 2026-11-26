<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter\Tests\Storage;

use Baander\RedisRateLimiter\Exception\RateLimiterUnavailableException;
use Baander\RedisRateLimiter\Limiter\ContinuousTokenBucket;
use Baander\RedisRateLimiter\Storage\RateLimiterStateCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RateLimiterStateCodecTest extends TestCase
{
    public function testRoundTripPreservesPolicyStateAndReservationTtl(): void
    {
        $codec = new RateLimiterStateCodec($this->config(), 'test-subject');
        $state = new Window('test-subject', 60, 3);
        $state->add(5);
        $copy = $codec->decode($codec->encode($state));
        self::assertEquals($state, $copy);
        self::assertSame(120, $copy?->getExpirationTime());
        self::assertNull($codec->decode(''));
    }

    /** @return iterable<string, array{string}> */
    public static function corruptions(): iterable
    {
        foreach (['malformed', 'incomplete object', 'wrong class', 'wrong id', 'wrong config', 'wrong interval', 'wrong limit', 'trailing bytes'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('corruptions')]
    public function testCorruptStateNeverBecomesAnAbsentBucket(string $kind): void
    {
        $config = $this->config();
        $state = match ($kind) {
            'wrong class' => new SlidingWindow('test-subject', 60),
            'wrong id' => new Window('other-subject', 60, 3),
            'wrong interval' => new Window('test-subject', 30, 3),
            'wrong limit' => new Window('test-subject', 60, 4),
            default => new Window('test-subject', 60, 3),
        };
        $payload = json_encode([
            'config' => $kind === 'wrong config' ? str_repeat('0', 64) : hash('sha256', serialize($config)),
            'state' => base64_encode($kind === 'incomplete object'
                ? sprintf('O:%d:"%s":0:{}', strlen(Window::class), Window::class)
                : serialize($state) . ($kind === 'trailing bytes' ? 'extra' : '')),
        ], JSON_THROW_ON_ERROR);
        $this->expectException(RateLimiterUnavailableException::class);
        (new RateLimiterStateCodec($config, 'test-subject'))->decode($kind === 'malformed' ? '{' : $payload);
    }

    public function testSlidingWindowRoundTripKeepsTheStateItsLimiterSaved(): void
    {
        $config = $this->config('sliding_window');
        $storage = new InMemoryStorage();
        new RateLimiterFactory($config, $storage)->create('subject')->consume(2);
        $state = $storage->fetch('test-subject');
        self::assertInstanceOf(SlidingWindow::class, $state);

        $codec = new RateLimiterStateCodec($config, 'test-subject');

        self::assertEquals($state, $codec->decode($codec->encode($state)));
    }

    /** @return iterable<string, array{?int}> */
    public static function slidingWindowSizes(): iterable
    {
        yield 'a size other than the limit' => [4];
        yield 'no size' => [null];
    }

    #[DataProvider('slidingWindowSizes')]
    public function testSlidingWindowWithoutTheLimitAsItsSizeIsCorrupt(?int $size): void
    {
        $state = new SlidingWindow('test-subject', 60);
        if ($size !== null) {
            $state->configure($size);
        }
        $config = $this->config('sliding_window');
        $payload = json_encode(['config' => hash('sha256', serialize($config)), 'state' => base64_encode(serialize($state))], JSON_THROW_ON_ERROR);
        $this->expectException(RateLimiterUnavailableException::class);
        new RateLimiterStateCodec($config, 'test-subject')->decode($payload);
    }

    public function testTokenBucketRoundTripKeepsItsRefillMomentAndOutlivesIt(): void
    {
        $codec = new RateLimiterStateCodec($this->tokenBucketConfig(), 'test-subject');
        $state = new ContinuousTokenBucket('test-subject', microtime(true) + 2.5);
        $copy = $codec->decode($codec->encode($state));
        self::assertEquals($state, $copy);
        self::assertSame(3, $copy?->getExpirationTime());
    }

    /** @return iterable<string, array{string}> */
    public static function tokenBucketCorruptions(): iterable
    {
        foreach (['wrong class', 'wrong id', 'negative moment', 'infinite moment', 'missing moment'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('tokenBucketCorruptions')]
    public function testCorruptTokenBucketNeverBecomesAFullBucket(string $kind): void
    {
        $config = $this->tokenBucketConfig();
        $serialized = match ($kind) {
            'wrong class' => new Window('test-subject', 1, 1) |> serialize(...),
            'wrong id' => new ContinuousTokenBucket('other-subject', 1.0) |> serialize(...),
            'negative moment' => new ContinuousTokenBucket('test-subject', -1.0) |> serialize(...),
            'infinite moment' => new ContinuousTokenBucket('test-subject', INF) |> serialize(...),
            default => sprintf('O:%d:"%s":1:{s:2:"id";s:12:"test-subject";}', strlen(ContinuousTokenBucket::class), ContinuousTokenBucket::class),
        };
        $payload = json_encode(['config' => hash('sha256', serialize($config)), 'state' => base64_encode($serialized)], JSON_THROW_ON_ERROR);
        $this->expectException(RateLimiterUnavailableException::class);
        new RateLimiterStateCodec($config, 'test-subject')->decode($payload);
    }

    /** @return array{id: string, policy: string, limit: int, rate: array{interval: string, amount: int}} */
    private function tokenBucketConfig(): array
    {
        return ['id' => 'test', 'policy' => 'token_bucket', 'limit' => 1, 'rate' => ['interval' => '1 second', 'amount' => 1]];
    }

    /** @return array{id: string, policy: string, limit: int, interval: string} */
    private function config(string $policy = 'fixed_window'): array
    {
        return ['id' => 'test', 'policy' => $policy, 'limit' => 3, 'interval' => '60 seconds'];
    }
}
