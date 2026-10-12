<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Cache;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Infrastructure\Cache\RedisDpopJtiCache;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Redis;
use RedisException;

final class RedisDpopJtiCacheTest extends TestCase
{
    /** @var array<string, array{value: string, ttl: int}> */
    private array $store = [];

    public function testAJtiIsFreshOnceAndAReplayAfterwardsWithinTheConfiguredWindow(): void
    {
        $cache = new RedisDpopJtiCache($this->workingFactory(), new NullLogger());

        self::assertFalse($cache->isReplay('proof-1', 420));
        self::assertTrue($cache->isReplay('proof-1', 420));
        self::assertFalse($cache->isReplay('proof-2', 420));

        self::assertCount(2, $this->store);
        foreach ($this->store as $key => $entry) {
            self::assertStringStartsWith('dpop:jti:', $key);
            self::assertSame(420, $entry['ttl'], 'Every jti record expires with the proof acceptance window.');
        }
    }

    public function testAnExhaustedPoolIsTheOutageSignal(): void
    {
        $cause = new RedisPoolExhaustedException(10, 10);
        $cache = new RedisDpopJtiCache($this->failingFactory($cause), $this->loggerWarningOnce());

        try {
            $cache->isReplay('proof-1', 420);
            self::fail('A jti cannot be judged while the jti store is unavailable.');
        } catch (DpopStoreUnavailableException $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    public function testAnUnreachableStoreIsTheOutageSignal(): void
    {
        $cause = new RedisException('read error on connection to redis.baander.app:6379');
        $cache = new RedisDpopJtiCache($this->failingFactory($cause), $this->loggerWarningOnce());

        $this->expectException(DpopStoreUnavailableException::class);
        $cache->isReplay('proof-1', 420);
    }

    private function workingFactory(): RedisClientFactory
    {
        $store = &$this->store;
        $redis = $this->createStub(Redis::class);
        // Only the atomic form is answered: SET key value NX EX <ttl>. A separate SETNX
        // plus EXPIRE could leave a key without a TTL, so the fake does not offer them.
        $redis->method('set')->willReturnCallback(static function (string $key, mixed $value, mixed $options) use (&$store): bool {
            self::assertIsArray($options);
            self::assertContains('NX', $options);
            self::assertIsInt($options['EX'] ?? null);
            if (isset($store[$key])) {
                return false;
            }
            $store[$key] = ['value' => (string) $value, 'ttl' => $options['EX']];

            return true;
        });

        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn (callable $callback): mixed => $callback($redis));

        return $factory;
    }

    private function failingFactory(\Throwable $cause): RedisClientFactory
    {
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willThrowException($cause);

        return $factory;
    }

    private function loggerWarningOnce(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $logger->expects($this->never())->method('error');

        return $logger;
    }
}
