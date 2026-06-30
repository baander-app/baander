<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Cache;

use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Psr\Log\LoggerInterface;
use Throwable;

final class RedisDpopJtiCache implements DpopJtiCacheInterface
{
    public function __construct(
        private readonly RedisClientFactory $redisClientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isReplay(string $jti, int $ttlSeconds = 3600): bool
    {
        $key = $this->cacheKey($jti);

        try {
            $isFirstUse = $this->redisClientFactory->borrow(function (\Redis $redis) use ($key, $ttlSeconds): bool {
                // SETNX atomically sets the key only if it doesn't exist.
                // Returns true if the key was set (first use), false if it already existed (replay).
                $isFirstUse = $redis->setnx($key, '1');

                if ($isFirstUse) {
                    $redis->expire($key, $ttlSeconds);
                }

                return $isFirstUse;
            });
        } catch (Throwable $e) {
            $this->logger->error('DPoP jti replay check failed', ['exception' => $e]);
            // Fail open — reject the proof rather than allowing a potential replay
            return true;
        }

        return !$isFirstUse;
    }

    public function store(string $jti, int $ttlSeconds): void
    {
        // Deprecated: isReplay() now handles storage atomically via SETNX.
        // Kept for interface compatibility — no-op since isReplay() stores on first use.
    }

    private function cacheKey(string $jti): string
    {
        return 'dpop:jti:' . hash('sha256', $jti);
    }
}
