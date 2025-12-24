<?php

namespace App\Modules\Transcoder;

use App\Modules\Transcoder\Exception\SocketException;
use App\Modules\Transcoder\Logging\LoggerInterface;
use App\Modules\Transcoder\Logging\NullLogger;
use Closure;

/**
 * Connection Pool for Unix Domain Sockets
 *
 * Manages a pool of reusable socket connections to improve performance
 * and reduce connection overhead for high-concurrency scenarios.
 */
class ConnectionPool
{
    /** @var array<int, PooledConnection> */
    private array $idleConnections = [];

    /** @var array<int, PooledConnection> */
    private array $activeConnections = [];

    private int $totalCreated = 0;
    private int $maxConnections;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly string          $socketPath = '/tmp/transcoder.sock',
        private readonly float           $timeout = 30.0,
        private readonly int             $maxMessageSize = 104857600,
        private readonly int             $maxPoolSize = 10,
        private readonly float           $idleTimeout = 60.0,
        private readonly array           $options = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    )
    {
        $this->maxConnections = $maxPoolSize;
        $this->logger->info('ConnectionPool created', [
            'socketPath'  => $socketPath,
            'maxPoolSize' => $maxPoolSize,
            'idleTimeout' => $idleTimeout,
        ]);
    }

    /**
     * Execute a callback with a connection from the pool
     *
     * @template T
     * @param Closure(SocketClient): T $callback
     * @return T
     * @throws SocketException
     */
    public function withConnection(Closure $callback): mixed
    {
        $pooledConnection = $this->acquire();

        try {
            $client = $pooledConnection->getClient();
            return $callback($client);
        } finally {
            $this->release($pooledConnection);
        }
    }

    /**
     * Acquire a connection from the pool
     *
     * @throws SocketException
     */
    public function acquire(): PooledConnection
    {
        // Try to reuse an idle connection
        while (count($this->idleConnections) > 0) {
            $connection = array_pop($this->idleConnections);

            if ($connection === null) {
                continue;
            }

            // Check if connection is still valid
            if ($this->isConnectionValid($connection)) {
                $this->activeConnections[$connection->getId()] = $connection;
                $this->logger->debug('Reusing idle connection', [
                    'connectionId'      => $connection->getId(),
                    'idleConnections'   => count($this->idleConnections),
                    'activeConnections' => count($this->activeConnections),
                ]);
                return $connection;
            }

            // Close invalid connection
            $this->logger->debug('Closing invalid idle connection', [
                'connectionId' => $connection->getId(),
            ]);
            $this->closeConnection($connection);
        }

        // No idle connections available, create new one if under limit
        if (count($this->activeConnections) < $this->maxConnections) {
            return $this->createNewConnection();
        }

        // Pool exhausted - wait briefly or create temporary connection
        $this->logger->warning('Pool exhausted, creating temporary connection', [
            'maxPoolSize'       => $this->maxPoolSize,
            'activeConnections' => count($this->activeConnections),
        ]);
        return $this->createNewConnection();
    }

    /**
     * Check if a connection is still valid
     */
    private function isConnectionValid(PooledConnection $connection): bool
    {
        // Check if connection has been idle too long
        if (time() - $connection->getLastUsed() > $this->idleTimeout) {
            return false;
        }

        // Check if underlying socket is still open
        return $connection->getClient()->isConnected();
    }

    /**
     * Close a pooled connection
     */
    private function closeConnection(PooledConnection $connection): void
    {
        try {
            $connection->getClient()->disconnect();
        } catch (SocketException $e) {
            // Ignore errors during close
        }
    }

    /**
     * Create a new connection
     *
     * @throws SocketException
     */
    private function createNewConnection(): PooledConnection
    {
        $this->logger->debug('Creating new connection', [
            'totalCreated'      => $this->totalCreated,
            'activeConnections' => count($this->activeConnections),
        ]);

        $client = new SocketClient(
            $this->socketPath,
            $this->timeout,
            $this->maxMessageSize,
            options: $this->options,
        );

        // Ensure we're connected
        if (!$client->isConnected()) {
            $client->connect();
        }

        $this->totalCreated++;
        $id = $this->totalCreated;

        $connection = new PooledConnection($id, $client, time());
        $this->activeConnections[$id] = $connection;

        $this->logger->info('New connection created', [
            'connectionId'      => $id,
            'totalCreated'      => $this->totalCreated,
            'activeConnections' => count($this->activeConnections),
        ]);

        return $connection;
    }

    /**
     * Return a connection to the pool
     */
    public function release(PooledConnection $connection): void
    {
        unset($this->activeConnections[$connection->getId()]);

        // Only return to pool if valid and pool not full
        if ($this->isConnectionValid($connection) && count($this->idleConnections) < $this->maxConnections) {
            $connection->setLastUsed(time());
            $this->idleConnections[$connection->getId()] = $connection;
            $this->logger->debug('Connection returned to pool', [
                'connectionId'    => $connection->getId(),
                'idleConnections' => count($this->idleConnections),
            ]);
        } else {
            $this->logger->debug('Closing connection', [
                'connectionId' => $connection->getId(),
                'reason'       => count($this->idleConnections) >= $this->maxPoolSize ? 'pool_full' : 'invalid',
            ]);
            $this->closeConnection($connection);
        }
    }

    /**
     * Close all idle connections
     */
    public function closeIdle(): void
    {
        $now = time();

        foreach ($this->idleConnections as $id => $connection) {
            if ($now - $connection->getLastUsed() > $this->idleTimeout) {
                $this->closeConnection($connection);
                unset($this->idleConnections[$id]);
            }
        }
    }

    /**
     * Get pool statistics
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'idleConnections'   => count($this->idleConnections),
            'activeConnections' => count($this->activeConnections),
            'totalCreated'      => $this->totalCreated,
            'maxPoolSize'       => $this->maxConnections,
        ];
    }

    public function __destruct()
    {
        $this->closeAll();
    }

    /**
     * Close all connections
     */
    public function closeAll(): void
    {
        $idleCount = count($this->idleConnections);
        $activeCount = count($this->activeConnections);

        // Close idle connections
        foreach ($this->idleConnections as $connection) {
            $this->closeConnection($connection);
        }
        $this->idleConnections = [];

        // Close active connections
        foreach ($this->activeConnections as $connection) {
            $this->closeConnection($connection);
        }
        $this->activeConnections = [];

        $this->logger->info('ConnectionPool closed all connections', [
            'idleClosed'   => $idleCount,
            'activeClosed' => $activeCount,
            'totalCreated' => $this->totalCreated,
        ]);
    }

    /**
     * Prevent cloning
     */
    private function __clone()
    {
    }
}
