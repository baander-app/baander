<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimiter;

use App\Shared\Infrastructure\RateLimiter\RateLimiterSnapshot;
use App\Shared\Infrastructure\RateLimiter\RateLimiterStateCodec;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterFactory;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RedisRateLimiterFactoryTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function policies(): iterable
    {
        yield 'fixed window' => ['fixed_window'];
        yield 'sliding window' => ['sliding_window'];
    }

    #[DataProvider('policies')]
    public function testConsumptionAndInspectionMatchInstalledSymfony(string $policy): void
    {
        $config = $this->config($policy);
        $actual = (new RedisRateLimiterFactory($config, $this->workingStore()))->create('member@baander.app');
        $expected = (new RateLimiterFactory($config, new InMemoryStorage()))->create('member@baander.app');
        foreach ([0, 2, 0, 1, 1, 0] as $tokens) {
            $a = $actual->consume($tokens);
            $e = $expected->consume($tokens);
            self::assertSame($e->isAccepted(), $a->isAccepted());
            self::assertSame($e->getRemainingTokens(), $a->getRemainingTokens());
            self::assertSame($e->getLimit(), $a->getLimit());
            self::assertEqualsWithDelta($e->getRetryAfter()->getTimestamp(), $a->getRetryAfter()->getTimestamp(), 1);
        }
    }

    #[DataProvider('policies')]
    public function testReservationsRetainDebtAndMaximumWaitSemantics(string $policy): void
    {
        $config = $this->config($policy);
        $actual = (new RedisRateLimiterFactory($config, $this->workingStore()))->create('subject');
        $expected = (new RateLimiterFactory($config, new InMemoryStorage()))->create('subject');
        foreach ([3, 2] as $tokens) {
            $a = $actual->reserve($tokens);
            $e = $expected->reserve($tokens);
            self::assertSame($e->getRateLimit()->isAccepted(), $a->getRateLimit()->isAccepted());
            self::assertSame($e->getRateLimit()->getRemainingTokens(), $a->getRateLimit()->getRemainingTokens());
            self::assertEqualsWithDelta($e->getTimeToAct(), $a->getTimeToAct(), 0.1);
        }
        $this->expectException(MaxWaitDurationExceededException::class);
        $actual->reserve(1, 0);
    }

    public function testChangedConfigurationAndDifferentSubjectsHaveIndependentBudgets(): void
    {
        $store = $this->workingStore();
        $config = $this->config('fixed_window');
        $first = new RedisRateLimiterFactory($config, $store);
        self::assertTrue($first->create('one@baander.app')->consume(3)->isAccepted());
        self::assertFalse($first->create('one@baander.app')->consume()->isAccepted());
        self::assertTrue($first->create('two@baander.app')->consume(3)->isAccepted());
        $config['limit'] = 4;
        self::assertTrue((new RedisRateLimiterFactory($config, $store))->create('one@baander.app')->consume(4)->isAccepted());
    }

    public function testInvalidCountsAreRejectedBeforeStorage(): void
    {
        $store = $this->createMock(RedisRateLimiterStore::class);
        $store->expects($this->never())->method('read');
        $limiter = (new RedisRateLimiterFactory($this->config('fixed_window'), $store))->create('subject');
        foreach ([-1, 4] as $count) {
            try {
                $limiter->consume($count);
                self::fail('An invalid count must not inspect shared storage.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Token count must be between zero and the limiter capacity.', $exception->getMessage());
            }
        }
    }

    public function testUnsupportedPolicyIsRejectedRatherThanBecomingUnlimited(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedisRateLimiterFactory($this->config('token_bucket'), $this->workingStore());
    }

    public function testReadOnlyInspectionDoesNotCommitState(): void
    {
        $store = $this->createMock(RedisRateLimiterStore::class);
        $store->expects($this->once())->method('read')->willReturn(new RateLimiterSnapshot('test', 'subject', str_repeat('a', 32), str_repeat('b', 32), '', 1));
        $store->expects($this->never())->method('commit');
        self::assertSame(3, (new RedisRateLimiterFactory($this->config('fixed_window'), $store))->create('subject')->consume(0)->getRemainingTokens());
    }

    /** @return iterable<string, array{string, string}> */
    public static function seededWindows(): iterable
    {
        yield 'fixed window expired with reservation debt' => ['fixed_window', 'debt'];
        yield 'fixed window before rollover' => ['fixed_window', 'active'];
        yield 'sliding current and previous weighted windows' => ['sliding_window', 'active'];
        yield 'sliding rollover carries preceding hits' => ['sliding_window', 'rollover'];
        yield 'sliding idle windows discard old hits' => ['sliding_window', 'idle'];
    }

    #[DataProvider('seededWindows')]
    public function testSeededRolloverWeightingAndDebtMatchInstalledSymfony(string $policy, string $kind): void
    {
        $config = $this->config($policy);
        $now = microtime(true);
        $seed = static function (string $id) use ($policy, $kind, $now): LimiterStateInterface {
            if ($policy === 'fixed_window') {
                $state = new Window($id, 60, 3, $now - ($kind === 'debt' ? 61 : 59));
                $state->add($kind === 'debt' ? 5 : 2, $now - ($kind === 'debt' ? 61 : 59));

                return $state;
            }
            $state = new SlidingWindow($id, 60);
            $end = $now + match ($kind) { 'active' => 30.5, 'rollover' => -15.3, default => -121 };
            $state->__unserialize([pack('NNN', 2, 1, 60) . $id => $end]);

            return $state;
        };
        $actual = (new RedisRateLimiterFactory($config, $this->workingStore($config, $seed)))->create('subject');
        $storage = new InMemoryStorage();
        $storage->save($seed('test-subject'));
        $expected = (new RateLimiterFactory($config, $storage))->create('subject');
        foreach ([0, 1, 0, 2] as $tokens) {
            $a = $actual->consume($tokens);
            $e = $expected->consume($tokens);
            self::assertSame($e->isAccepted(), $a->isAccepted());
            self::assertSame($e->getRemainingTokens(), $a->getRemainingTokens());
            self::assertEqualsWithDelta($e->getRetryAfter()->getTimestamp(), $a->getRetryAfter()->getTimestamp(), 1);
        }
    }

    /** @return array{id: string, policy: string, limit: int, interval: string} */
    private function config(string $policy): array
    {
        return ['id' => 'test', 'policy' => $policy, 'limit' => 3, 'interval' => '60 seconds'];
    }

    /** @param array<string, mixed>|null $config
     * @param (callable(string): LimiterStateInterface)|null $seed
     */
    private function workingStore(?array $config = null, ?callable $seed = null): RedisRateLimiterStore
    {
        $payloads = [];
        $store = $this->createStub(RedisRateLimiterStore::class);
        $store->method('read')->willReturnCallback(static function (string $name, string $subject) use (&$payloads, $config, $seed): RateLimiterSnapshot {
            if ($config !== null && $seed !== null && !isset($payloads[$name . $subject])) {
                $id = $name . '-' . $subject;
                $payloads[$name . $subject] = (new RateLimiterStateCodec($config, $id))->encode($seed($id));
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
