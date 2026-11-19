<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Cache;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Port\ClientAssertionJtiStoreInterface;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use Psr\Log\LoggerInterface;
use RedisException;

/**
 * Records client assertion jtis in Redis, keyed per client and apart from DPoP proof jtis.
 */
final readonly class RedisClientAssertionJtiStore implements ClientAssertionJtiStoreInterface
{
    public function __construct(
        private RedisClientFactory $redisClientFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function isReplay(string $clientId, string $jti, int $ttlSeconds): bool
    {
        $key = self::key($clientId, $jti);

        try {
            // SET NX EX records the jti and its expiry in one command; false means it was seen before.
            $isFirstUse = $this->redisClientFactory->borrow(
                static fn (\Redis $redis): bool => $redis->set($key, '1', ['NX', 'EX' => $ttlSeconds]) === true,
            );
        } catch (RedisException | RedisPoolExhaustedException $e) {
            $this->logger->warning('Client assertion jti replay check failed', ['exception' => $e]);

            throw DpopStoreUnavailableException::assertionJtiStore($e);
        }

        return !$isFirstUse;
    }

    /** A public client ID never holds a NUL byte, so the pair hashes without ambiguity. */
    private static function key(string $clientId, string $jti): string
    {
        return 'oauth:assertion-jti:' . hash('sha256', $clientId . "\0" . $jti);
    }
}
