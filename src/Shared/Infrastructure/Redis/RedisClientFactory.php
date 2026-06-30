<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Redis;

use InvalidArgumentException;
use Redis;
use Throwable;

/**
 * Connection pool for Redis connections, coroutine-aware via Swoole.
 *
 * Provides two APIs:
 *   - borrow(callable): short-lived ops, auto-returns connection after callback
 *   - checkout()/release(): long-lived ops, explicit return with orphan reclamation
 *
 * Pool health is maintained via ping validation on idle connections,
 * idle timeout eviction, and orphan reclamation for checked-out connections
 * whose coroutine has exited.
 */
class RedisClientFactory
{
    /** @var array{host: string, port: int, password: string|null} */
    private readonly array $parsed;

    /** @var list<array{redis: Redis, idleSince: float}> Idle connections ready for reuse */
    private array $idle = [];

    /** @var array<int, Redis> Connections checked out, keyed by spl_object_id */
    private array $checkedOut = [];

    /** @var array<int, int> Map of spl_object_id => coroutine ID for orphan tracking */
    private array $checkoutCids = [];

    private readonly int $maxSize;
    private readonly int $idleTimeoutSeconds;

    /** @var callable(): Redis */
    private readonly mixed $connectionFactory;

    /** @var callable(): int */
    private readonly mixed $getCidFn;

    /** @var callable(int): bool */
    private readonly mixed $cidExistsFn;

    /**
     * @param string $dsn Redis DSN (redis://[user:pass@]host:port)
     * @param int $maxSize Maximum connections in the pool
     * @param int $idleTimeoutSeconds Seconds before idle connections are evicted
     * @param (callable(): Redis)|null $connectionFactory Override connection creation (for testing)
     * @param (callable(): int)|null $getCidFn Override coroutine ID resolution (for testing)
     * @param (callable(int): bool)|null $cidExistsFn Override coroutine existence check (for testing)
     */
    public function __construct(
        string $dsn,
        int $maxSize = 10,
        int $idleTimeoutSeconds = 30,
        ?callable $connectionFactory = null,
        ?callable $getCidFn = null,
        ?callable $cidExistsFn = null,
    ) {
        if (!preg_match('#^redis://(?:[^:@]+:(?<pass>[^@]+)@)?(?<host>[^:]+):(?<port>\d+)$#', $dsn, $m)) {
            throw new InvalidArgumentException(sprintf('Cannot parse Redis DSN: "%s"', $dsn));
        }

        $this->parsed = [
            'host'     => $m['host'],
            'port'     => (int) $m['port'],
            'password' => $m['pass'] !== '' ? $m['pass'] : null,
        ];

        $this->maxSize = $maxSize;
        $this->idleTimeoutSeconds = $idleTimeoutSeconds;
        $this->connectionFactory = $connectionFactory ?? fn(): Redis => $this->createConnection();
        $this->getCidFn = $getCidFn ?? static fn(): int => \extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0
            ? \Swoole\Coroutine::getCid()
            : -1;
        $this->cidExistsFn = $cidExistsFn ?? static fn(int $cid): bool => \extension_loaded('swoole')
            ? \Swoole\Coroutine::exists($cid)
            : true;
    }

    /**
     * Executes a callback with a pooled Redis connection.
     *
     * Connection is automatically returned to the pool after the callback completes,
     * even if an exception is thrown.
     *
     * @template T
     * @param callable(Redis): T $callback
     * @return T
     */
    public function borrow(callable $callback): mixed
    {
        $redis = $this->checkoutRaw();
        try {
            return $callback($redis);
        } finally {
            $this->returnConnection($redis);
        }
    }

    /**
     * Checks out a connection from the pool for long-lived use.
     *
     * The caller MUST call release() on the returned wrapper when done.
     * If release() is not called, the connection will be reclaimed as an orphan
     * on the next pool interaction.
     */
    public function checkout(): ManagedRedisConnection
    {
        $this->reclaimOrphans();

        $cid = ($this->getCidFn)();
        $redis = $this->getIdleOrCreate($cid);

        return new ManagedRedisConnection(
            redis: $redis,
            pool: $this,
            coroutineId: $cid,
        );
    }

    /**
     * Returns a connection to the idle pool (called by ManagedRedisConnection::release() or borrow()).
     */
    public function returnConnection(Redis $redis): void
    {
        // Remove from checked-out tracking
        $oid = spl_object_id($redis);
        unset($this->checkedOut[$oid], $this->checkoutCids[$oid]);

        // Skip validation on return — connection just completed a successful operation.
        // Validation happens on checkout from idle pool.
        $this->idle[] = ['redis' => $redis, 'idleSince' => microtime(true)];
    }

    /**
     * Closes all connections and clears the pool. Call on worker shutdown.
     */
    public function dispose(): void
    {
        foreach ($this->idle as $entry) {
            $this->closeConnection($entry['redis']);
        }
        foreach ($this->checkedOut as $redis) {
            $this->closeConnection($redis);
        }

        $this->idle = [];
        $this->checkedOut = [];
        $this->checkoutCids = [];
    }

    /**
     * @return array{active: int, idle: int, total: int}
     */
    public function getMetrics(): array
    {
        return [
            'active' => count($this->checkedOut),
            'idle'   => count($this->idle),
            'total'  => count($this->checkedOut) + count($this->idle),
        ];
    }

    // --- Internal ---

    private function checkoutRaw(): Redis
    {
        $this->reclaimOrphans();

        $cid = ($this->getCidFn)();
        return $this->getIdleOrCreate($cid);
    }

    private function getIdleOrCreate(int $cid): Redis
    {
        $now = microtime(true);

        // Try to reuse an idle connection, evicting stale ones
        while (count($this->idle) > 0) {
            $entry = array_pop($this->idle);
            $redis = $entry['redis'];

            // Evict if idle too long
            if (($now - $entry['idleSince']) >= $this->idleTimeoutSeconds) {
                $this->closeConnection($redis);
                continue;
            }

            if ($this->validateConnection($redis)) {
                $oid = spl_object_id($redis);
                $this->checkedOut[$oid] = $redis;
                $this->checkoutCids[$oid] = $cid;
                return $redis;
            }
            $this->closeConnection($redis);
        }

        // Create new if under max
        $totalCount = count($this->checkedOut) + count($this->idle);
        if ($totalCount >= $this->maxSize) {
            throw new RedisPoolExhaustedException($this->maxSize, count($this->checkedOut));
        }

        $redis = ($this->connectionFactory)();
        $oid = spl_object_id($redis);
        $this->checkedOut[$oid] = $redis;
        $this->checkoutCids[$oid] = $cid;

        return $redis;
    }

    private function reclaimOrphans(): void
    {
        foreach ($this->checkoutCids as $oid => $cid) {
            if ($cid > 0 && !($this->cidExistsFn)($cid)) {
                // Coroutine no longer exists — reclaim connection
                $redis = $this->checkedOut[$oid] ?? null;
                unset($this->checkedOut[$oid], $this->checkoutCids[$oid]);
                if ($redis !== null) {
                    $this->idle[] = ['redis' => $redis, 'idleSince' => microtime(true)];
                }
            }
        }
    }

    private function validateConnection(Redis $redis): bool
    {
        try {
            return $redis->ping() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function closeConnection(Redis $redis): void
    {
        try {
            $redis->close();
        } catch (Throwable) {
            // ignore — connection may already be dead
        }
    }

    /**
     * Creates a Redis connection outside the pool.
     * Caller is responsible for closing the connection when done.
     */
    public function createStandaloneConnection(): Redis
    {
        return $this->createConnection();
    }

    /**
     * Creates a fresh Redis connection using the parsed DSN.
     */
    private function createConnection(): Redis
    {
        $redis = new Redis();
        $redis->connect($this->parsed['host'], $this->parsed['port'], 2.0);

        if ($this->parsed['password'] !== null) {
            $redis->auth($this->parsed['password']);
        }

        return $redis;
    }
}
