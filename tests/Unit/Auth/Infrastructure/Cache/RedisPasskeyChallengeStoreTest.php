<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Cache;

use App\Auth\Application\Exception\PasskeyChallengeUnavailableException;
use App\Auth\Infrastructure\Cache\RedisPasskeyChallengeStore;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;

final class RedisPasskeyChallengeStoreTest extends TestCase
{
    /** @var array<string, array{value: string, ttl: int}> */
    private array $redis = [];

    public function testAChallengeIsTakenOnceEvenByAnotherStoreOverTheSameRedis(): void
    {
        $factory = $this->workingFactory();
        $issuingWorker = new RedisPasskeyChallengeStore($factory);
        $otherWorker = new RedisPasskeyChallengeStore($factory);

        $issuingWorker->put('login', 'challenge-key', '{"challenge":"abc"}', 300);

        self::assertSame('{"challenge":"abc"}', $otherWorker->take('login', 'challenge-key'));
        self::assertNull($issuingWorker->take('login', 'challenge-key'));
        self::assertSame([], $this->redis);
    }

    public function testAChallengeIsTakenOnlyUnderTheCeremonyItWasStoredFor(): void
    {
        $store = new RedisPasskeyChallengeStore($this->workingFactory());
        $store->put('registration:aaaaaaaaaaaaaaaaaaaaa', 'challenge-key', '{"challenge":"abc"}', 300);

        self::assertNull($store->take('registration:bbbbbbbbbbbbbbbbbbbbb', 'challenge-key'));
        self::assertNull($store->take('login', 'challenge-key'));
        self::assertSame('{"challenge":"abc"}', $store->take('registration:aaaaaaaaaaaaaaaaaaaaa', 'challenge-key'));
    }

    public function testTheChallengeExpiresWithItsTtlAndTheKeyHidesTheChallengeKey(): void
    {
        $store = new RedisPasskeyChallengeStore($this->workingFactory());
        $store->put('login', 'challenge-key', '{"challenge":"abc"}', 300);

        self::assertSame(['auth:passkey-challenge:login:' . hash('sha256', 'challenge-key')], array_keys($this->redis));
        self::assertSame(300, array_first($this->redis)['ttl']);
    }

    public function testAnUnreachableOrExhaustedRedisIsTheOutageSignal(): void
    {
        foreach ([new RedisException('Connection refused'), new RedisPoolExhaustedException(10, 10)] as $cause) {
            $factory = $this->createStub(RedisClientFactory::class);
            $factory->method('borrow')->willThrowException($cause);
            $store = new RedisPasskeyChallengeStore($factory);

            foreach ([fn () => $store->put('login', 'challenge-key', '{}', 300), fn () => $store->take('login', 'challenge-key')] as $operation) {
                try {
                    $operation();
                    self::fail('A challenge is never kept or read anywhere but Redis.');
                } catch (PasskeyChallengeUnavailableException $exception) {
                    self::assertSame($cause, $exception->getPrevious());
                }
            }
        }
    }

    private function workingFactory(): RedisClientFactory
    {
        $store = &$this->redis;
        $redis = $this->createStub(Redis::class);
        $redis->method('set')->willReturnCallback(static function (string $key, mixed $value, mixed $options) use (&$store): bool {
            self::assertIsArray($options);
            self::assertContains('NX', $options);
            if (isset($store[$key])) {
                return false;
            }
            $store[$key] = ['value' => (string) $value, 'ttl' => $options['EX']];

            return true;
        });
        // Only the atomic read-and-delete is offered: a GET followed by a DEL could let two
        // workers both read the challenge before either deleted it.
        $redis->method('getDel')->willReturnCallback(static function (string $key) use (&$store): string|false {
            $value = $store[$key]['value'] ?? false;
            unset($store[$key]);

            return $value;
        });

        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn (callable $callback): mixed => $callback($redis));

        return $factory;
    }
}
