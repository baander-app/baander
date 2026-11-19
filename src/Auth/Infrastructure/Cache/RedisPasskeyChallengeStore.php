<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Cache;

use App\Auth\Application\Exception\PasskeyChallengeUnavailableException;
use App\Auth\Application\Port\PasskeyChallengeStoreInterface;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use RedisException;

/**
 * Keeps passkey challenges in Redis only: Swoole workers share no PHP memory, so a
 * challenge must be readable, and spent, from whichever worker the next request lands on.
 */
final readonly class RedisPasskeyChallengeStore implements PasskeyChallengeStoreInterface
{
    public function __construct(private RedisClientFactory $redisClientFactory) {}

    public function put(string $ceremony, string $challengeKey, string $options, int $ttlSeconds): void
    {
        $key = self::key($ceremony, $challengeKey);
        try {
            $stored = $this->redisClientFactory->borrow(
                static fn(\Redis $redis): bool => $redis->set($key, $options, ['NX', 'EX' => $ttlSeconds]) === true,
            );
        } catch (RedisException | RedisPoolExhaustedException $exception) {
            throw new PasskeyChallengeUnavailableException('Passkey challenge storage is unavailable.', previous: $exception);
        }
        if (!$stored) {
            throw new PasskeyChallengeUnavailableException('Passkey challenge storage refused the challenge.');
        }
    }

    public function take(string $ceremony, string $challengeKey): ?string
    {
        $key = self::key($ceremony, $challengeKey);
        try {
            // GETDEL reads and removes in one command, so two workers can never both spend it.
            $options = $this->redisClientFactory->borrow(static fn(\Redis $redis): mixed => $redis->getDel($key));
        } catch (RedisException | RedisPoolExhaustedException $exception) {
            throw new PasskeyChallengeUnavailableException('Passkey challenge storage is unavailable.', previous: $exception);
        }

        return is_string($options) ? $options : null;
    }

    private static function key(string $ceremony, string $challengeKey): string
    {
        return 'auth:passkey-challenge:' . $ceremony . ':' . hash('sha256', $challengeKey);
    }
}
