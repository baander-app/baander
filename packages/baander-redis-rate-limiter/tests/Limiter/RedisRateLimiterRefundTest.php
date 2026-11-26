<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter\Tests\Limiter;

use Baander\RedisRateLimiter\Limiter\RedisRateLimiterFactory;
use Baander\RedisRateLimiter\Storage\RateLimiterSnapshot;
use Baander\RedisRateLimiter\Storage\RateLimiterStateCodec;
use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\Policy\Window;

/** A refund gives back tokens an attempt took and did not use, never more than the count holds. */
final class RedisRateLimiterRefundTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function policies(): iterable
    {
        yield 'fixed window' => ['fixed_window'];
        yield 'sliding window' => ['sliding_window'];
    }

    #[DataProvider('policies')]
    public function testARefundGivesBackWhatAConsumeTook(string $policy): void
    {
        $limiter = new RedisRateLimiterFactory($this->config($policy), $this->workingStore())->create('member@baander.app');
        self::assertTrue($limiter->consume(3)->isAccepted());
        self::assertFalse($limiter->consume()->isAccepted());

        $limiter->refund(2);

        self::assertSame(2, $limiter->consume(0)->getRemainingTokens());
        self::assertTrue($limiter->consume(2)->isAccepted());
        self::assertFalse($limiter->consume()->isAccepted());
    }

    #[DataProvider('policies')]
    public function testARefundNeverTakesTheCountBelowZero(string $policy): void
    {
        $limiter = new RedisRateLimiterFactory($this->config($policy), $this->workingStore())->create('subject');
        self::assertTrue($limiter->consume()->isAccepted());

        $limiter->refund(3);

        self::assertSame(3, $limiter->consume(0)->getRemainingTokens());
        self::assertTrue($limiter->consume(3)->isAccepted());
        self::assertFalse($limiter->consume()->isAccepted());
    }

    /** @return iterable<string, array{string, callable(string, float): LimiterStateInterface}> */
    public static function rolledOverWindows(): iterable
    {
        yield 'fixed window past its interval' => ['fixed_window', static function (string $id, float $now): LimiterStateInterface {
            $window = new Window($id, 60, 3, $now - 61);
            $window->add(1, $now - 61);

            return $window;
        }];
        yield 'sliding window that ended during the interval before' => ['sliding_window', static function (string $id, float $now): LimiterStateInterface {
            $window = new SlidingWindow($id, 60);
            $window->__unserialize([pack('NNN', 1, 0, 60) . $id => $now - 15, '' => 3]);

            return $window;
        }];
        yield 'sliding window that ended over an interval ago' => ['sliding_window', static function (string $id, float $now): LimiterStateInterface {
            $window = new SlidingWindow($id, 60);
            $window->__unserialize([pack('NNN', 1, 0, 60) . $id => $now - 121, '' => 3]);

            return $window;
        }];
    }

    /** @param callable(string, float): LimiterStateInterface $seed */
    #[DataProvider('rolledOverWindows')]
    public function testARefundAfterTheWindowRolledOverLeavesTheCountAtZero(string $policy, callable $seed): void
    {
        $config = $this->config($policy);
        $now = microtime(true);
        $limiter = new RedisRateLimiterFactory($config, $this->workingStore($config, static fn (string $id): LimiterStateInterface => $seed($id, $now)))->create('subject');

        $limiter->refund();

        self::assertSame(3, $limiter->consume(0)->getRemainingTokens());
        self::assertTrue($limiter->consume(3)->isAccepted());
        self::assertFalse($limiter->consume()->isAccepted());
    }

    /** A reset or a named clear leaves no state, so there is nothing to give back and nothing is written. */
    public function testARefundWithNoStoredStateWritesNothing(): void
    {
        $store = $this->createMock(RedisRateLimiterStore::class);
        $store->expects($this->once())->method('read')->willReturn(new RateLimiterSnapshot('test', 'subject', str_repeat('a', 32), str_repeat('b', 32), '', 1));
        $store->expects($this->never())->method('commit');

        new RedisRateLimiterFactory($this->config('sliding_window'), $store)->create('subject')->refund();
    }

    public function testARefundThatLosesTheComparisonReadsAgain(): void
    {
        $config = $this->config('fixed_window');
        $store = $this->createMock(RedisRateLimiterStore::class);
        $store->expects($this->exactly(2))->method('read')->willReturnCallback(static function (string $name, string $subject) use ($config): RateLimiterSnapshot {
            $window = new Window($name . '-' . $subject, 60, 3);
            $window->add(2);

            return new RateLimiterSnapshot($name, $subject, str_repeat('a', 32), str_repeat('b', 32), new RateLimiterStateCodec($config, $window->getId())->encode($window), 1);
        });
        $commits = [false, true];
        $store->expects($this->exactly(2))->method('commit')->willReturnCallback(static function () use (&$commits): bool {
            return array_shift($commits);
        });

        new RedisRateLimiterFactory($config, $store)->create('subject')->refund();
    }

    public function testInvalidCountsAreRejectedBeforeStorage(): void
    {
        $store = $this->createMock(RedisRateLimiterStore::class);
        $store->expects($this->never())->method('read');
        $limiter = new RedisRateLimiterFactory($this->config('sliding_window'), $store)->create('subject');
        foreach ([-1, 4] as $count) {
            try {
                $limiter->refund($count);
                self::fail('An invalid count must not touch shared storage.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Token count must be between zero and the limiter capacity.', $exception->getMessage());
            }
        }
    }

    /** A token bucket counts no hits to give back; its refills already return every token. */
    public function testATokenBucketRefusesARefund(): void
    {
        $store = $this->createMock(RedisRateLimiterStore::class);
        $store->expects($this->never())->method('read');
        $config = ['id' => 'test', 'policy' => 'token_bucket', 'limit' => 3, 'rate' => ['interval' => '1 second', 'amount' => 1]];

        $this->expectException(\LogicException::class);

        new RedisRateLimiterFactory($config, $store)->create('subject')->refund();
    }

    /** @return array{id: string, policy: string, limit: int, interval: string} */
    private function config(string $policy): array
    {
        return ['id' => 'test', 'policy' => $policy, 'limit' => 3, 'interval' => '60 seconds'];
    }

    /**
     * An in-memory store whose commits always win; $seed supplies a subject's first state.
     *
     * @param array<string, mixed>|null $config
     * @param (callable(string): LimiterStateInterface)|null $seed
     */
    private function workingStore(?array $config = null, ?callable $seed = null): RedisRateLimiterStore
    {
        $payloads = [];
        $store = $this->createStub(RedisRateLimiterStore::class);
        $store->method('read')->willReturnCallback(static function (string $name, string $subject) use (&$payloads, $config, $seed): RateLimiterSnapshot {
            if ($config !== null && $seed !== null && !isset($payloads[$name . $subject])) {
                $id = $name . '-' . $subject;
                $payloads[$name . $subject] = new RateLimiterStateCodec($config, $id)->encode($seed($id));
            }

            return new RateLimiterSnapshot($name, $subject, str_repeat('a', 32), str_repeat('b', 32), $payloads[$name . $subject] ?? '', 1);
        });
        $store->method('commit')->willReturnCallback(static function (RateLimiterSnapshot $snapshot, string $payload, int $ttl) use (&$payloads): bool {
            self::assertGreaterThan(0, $ttl);
            $payloads[$snapshot->name . $snapshot->subject] = $payload;

            return true;
        });

        return $store;
    }
}
