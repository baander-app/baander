<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Cache;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use Psr\Log\LoggerInterface;
use RedisException;

final class RedisDpopJtiCache implements DpopJtiCacheInterface
{
    public function __construct(
        private readonly RedisClientFactory $redisClientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isReplay(string $jti, int $ttlSeconds): bool
    {
        $key = $this->cacheKey($jti);

        try {
            $isFirstUse = $this->redisClientFactory->borrow(
                // SET NX EX records the jti and its expiry in one command, so no key
                // outlives the acceptance window: a separate EXPIRE could be lost
                // between the two commands. The key is set only when it is absent;
                // false means the jti was seen before (a replay).
                static fn(\Redis $redis): bool => $redis->set($key, '1', ['NX', 'EX' => $ttlSeconds]) === true,
            );
        } catch (RedisException | RedisPoolExhaustedException $e) {
            $this->logger->warning('DPoP jti replay check failed', ['exception' => $e]);

            throw DpopStoreUnavailableException::jtiStore($e);
        }

        return !$isFirstUse;
    }

    private function cacheKey(string $jti): string
    {
        return 'dpop:jti:' . hash('sha256', $jti);
    }
}
