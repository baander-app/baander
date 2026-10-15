<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Cache;

use App\Auth\Application\DTO\StoredWebSocketTicket;
use App\Auth\Application\DTO\WebSocketIdentity;
use App\Auth\Application\Exception\WebSocketTicketUnavailableException;
use App\Auth\Infrastructure\Cache\RedisWebSocketTicketStore;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use PHPUnit\Framework\TestCase;

final class RedisWebSocketTicketStoreTest extends TestCase
{
    public function testIssuanceNeverReturnsSuccessWhenRedisRefusesTheAtomicWrite(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('set')->willReturn(false);
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn(callable $callback): mixed => $callback($redis));
        $store = new RedisWebSocketTicketStore($factory);
        $this->expectException(WebSocketTicketUnavailableException::class);
        $store->put(str_repeat('a', 64), new StoredWebSocketTicket(
            new WebSocketIdentity('user', 'token'), 'https://baander.app', '/api/ws', new \DateTimeImmutable('+30 seconds'),
        ));
    }

    public function testPoolExhaustionIsAvailabilityFailureRatherThanInvalidTicket(): void
    {
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willThrowException(new RedisPoolExhaustedException(1, 1));
        $this->expectException(WebSocketTicketUnavailableException::class);
        (new RedisWebSocketTicketStore($factory))->consume(str_repeat('a', 64), 'https://baander.app', '/api/ws');
    }
}
