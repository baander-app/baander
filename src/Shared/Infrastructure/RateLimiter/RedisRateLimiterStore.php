<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimiter;

use App\Shared\Application\Exception\RateLimiterUnavailableException;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use RedisException;

/** Redis scripts establish the snapshot and commit boundaries across workers. */
class RedisRateLimiterStore
{
    private const string READ = <<<'LUA'
local generation = redis.call('GET', KEYS[1])
local exists = redis.call('EXISTS', KEYS[2])
if not generation then
    if exists == 1 then return redis.error_reply('Missing limiter generation') end
    generation = ARGV[1]
    redis.call('SET', KEYS[1], generation)
end
local stored_generation = redis.call('HGET', KEYS[2], 'generation')
local revision = redis.call('HGET', KEYS[2], 'revision')
local payload = redis.call('HGET', KEYS[2], 'payload')
if exists == 1 and (not stored_generation or #stored_generation ~= 32 or string.find(stored_generation, '[^0-9a-f]')) then
    return redis.error_reply('Invalid limiter record')
end
if exists == 0 or stored_generation ~= generation then
    revision = ARGV[2]
    payload = ''
    redis.call('DEL', KEYS[2])
    redis.call('HSET', KEYS[2], 'generation', generation, 'revision', revision, 'payload', payload)
    if redis.call('PEXPIRE', KEYS[2], 10000) ~= 1 then
        return redis.error_reply('Limiter expiry unavailable')
    end
elseif not revision or not payload or redis.call('HLEN', KEYS[2]) ~= 3 then
    return redis.error_reply('Invalid limiter record')
end
local now = redis.call('TIME')
local milliseconds = now[1] * 1000 + math.floor(now[2] / 1000)
return {generation, revision, payload, milliseconds}
LUA;

    private const string COMMIT = <<<'LUA'
local now = redis.call('TIME')
local milliseconds = now[1] * 1000 + math.floor(now[2] / 1000)
local age = milliseconds - tonumber(ARGV[3])
if age < 0 or age > 2000 then return -1 end
if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
if redis.call('HGET', KEYS[2], 'generation') ~= ARGV[1] then return 0 end
if redis.call('HGET', KEYS[2], 'revision') ~= ARGV[2] then return 0 end
redis.call('HSET', KEYS[2], 'generation', ARGV[1], 'revision', ARGV[4], 'payload', ARGV[5])
if redis.call('PEXPIRE', KEYS[2], ARGV[6]) ~= 1 then
    return redis.error_reply('Limiter expiry unavailable')
end
return 1
LUA;

    private const string RESET = <<<'LUA'
local generation = redis.call('GET', KEYS[1])
if not generation then
    if redis.call('EXISTS', KEYS[2]) == 1 then return redis.error_reply('Missing limiter generation') end
    generation = ARGV[1]
    redis.call('SET', KEYS[1], generation)
end
redis.call('DEL', KEYS[2])
redis.call('HSET', KEYS[2], 'generation', generation, 'revision', ARGV[2], 'payload', '')
if redis.call('PEXPIRE', KEYS[2], 10000) ~= 1 then
    return redis.error_reply('Limiter expiry unavailable')
end
return 1
LUA;

    public function __construct(private readonly RedisClientFactory $redis) {}

    public static function namespace(string $name): string
    {
        return 'baander:rate-limiter:{' . hash('sha256', $name) . '}';
    }

    public function read(string $name, string $subject): RateLimiterSnapshot
    {
        $reply = $this->evaluate(self::READ, self::keys($name, $subject), [self::revision(), self::revision()]);
        if (!is_array($reply) || !array_is_list($reply) || count($reply) !== 4
            || !is_string($reply[0]) || !self::validRevision($reply[0])
            || !is_string($reply[1]) || !self::validRevision($reply[1])
            || !is_string($reply[2]) || strlen($reply[2]) > 16384
            || !is_int($reply[3]) || $reply[3] < 0
        ) {
            throw new RateLimiterUnavailableException();
        }

        return new RateLimiterSnapshot($name, $subject, $reply[0], $reply[1], $reply[2], $reply[3]);
    }

    /** Only a definite generation/revision conflict returns false. */
    public function commit(RateLimiterSnapshot $snapshot, string $payload, int $ttlSeconds): bool
    {
        if ($payload === '' || strlen($payload) > 16384 || $ttlSeconds < 1 || $ttlSeconds > intdiv(PHP_INT_MAX, 1000)) {
            throw new RateLimiterUnavailableException();
        }
        $reply = $this->evaluate(self::COMMIT, self::keys($snapshot->name, $snapshot->subject), [
            $snapshot->generation, $snapshot->revision, $snapshot->readAtMilliseconds,
            self::revision(), $payload, $ttlSeconds * 1000,
        ]);
        if ($reply !== 0 && $reply !== 1) {
            throw new RateLimiterUnavailableException();
        }

        return $reply === 1;
    }

    public function reset(string $name, string $subject): void
    {
        if ($this->evaluate(self::RESET, self::keys($name, $subject), [self::revision(), self::revision()]) !== 1) {
            throw new RateLimiterUnavailableException();
        }
    }

    public function clear(string $name): void
    {
        try {
            $cleared = $this->redis->borrow(static fn(\Redis $redis): bool => $redis->set(self::namespace($name) . ':generation', self::revision()) === true);
        } catch (RedisException | RedisPoolExhaustedException) {
            // The original error can contain endpoints or command arguments.
            throw new RateLimiterUnavailableException();
        }
        if (!$cleared) {
            throw new RateLimiterUnavailableException();
        }
    }

    /** @param list<string> $keys
     * @param list<string|int> $arguments
     */
    private function evaluate(string $script, array $keys, array $arguments): mixed
    {
        try {
            return $this->redis->borrow(static fn(\Redis $redis): mixed => $redis->eval($script, [...$keys, ...$arguments], count($keys)));
        } catch (RedisException | RedisPoolExhaustedException) {
            throw new RateLimiterUnavailableException();
        }
    }

    /** @return list<string> */
    private static function keys(string $name, string $subject): array
    {
        $prefix = self::namespace($name);

        return [$prefix . ':generation', $prefix . ':subject:' . $subject];
    }

    private static function revision(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function validRevision(string $revision): bool
    {
        return preg_match('/\A[0-9a-f]{32}\z/', $revision) === 1;
    }
}
