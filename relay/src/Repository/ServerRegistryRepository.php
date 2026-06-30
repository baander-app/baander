<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\ServerRegistry;
use DateTimeImmutable;

/**
 * SQLite-backed server registry.
 *
 * Uses raw PDO for maximum simplicity — no ORM overhead for a single-table service.
 * The SQLite file lives at the path injected from config.
 */
final class ServerRegistryRepository
{
    private const SCHEMA = <<<'SQL'
        DROP TABLE IF EXISTS server_registries;
        CREATE TABLE IF NOT EXISTS server_registries (
            api_key_hash TEXT NOT NULL PRIMARY KEY,
            public_id TEXT NOT NULL,
            url TEXT NOT NULL,
            name TEXT NOT NULL,
            version TEXT NOT NULL,
            api_key TEXT NOT NULL,
            last_heartbeat TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_server_registries_public_id ON server_registries(public_id)
    SQL;

    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $dbPath,
    ) {
    }

    /**
     * Register or update a server (upsert).
     *
     * Conflict resolution uses api_key_hash as the primary key.
     * Only the holder of the API key can update a server's URL.
     */
    public function register(ServerRegistry $server): void
    {
        $pdo = $this->getPdo();

        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO server_registries (api_key_hash, public_id, url, name, version, api_key, last_heartbeat, created_at, updated_at)
            VALUES (:api_key_hash, :public_id, :url, :name, :version, :api_key, :last_heartbeat, :created_at, :updated_at)
            ON CONFLICT(api_key_hash) DO UPDATE SET
                public_id = excluded.public_id,
                url = excluded.url,
                name = excluded.name,
                version = excluded.version,
                last_heartbeat = excluded.last_heartbeat,
                updated_at = excluded.updated_at
        SQL);

        $now = new DateTimeImmutable();
        $apiKeyHash = $server->getApiKeyHash();

        $stmt->execute([
            ':api_key_hash' => $apiKeyHash,
            ':public_id' => $server->publicId,
            ':url' => $server->url,
            ':name' => $server->name,
            ':version' => $server->version,
            ':api_key' => $server->apiKey ?? '',
            ':last_heartbeat' => $now->format(DateTimeImmutable::ATOM),
            ':created_at' => $server->createdAt?->format(DateTimeImmutable::ATOM) ?? $now->format(DateTimeImmutable::ATOM),
            ':updated_at' => $now->format(DateTimeImmutable::ATOM),
        ]);
    }

    /**
     * Find a server by its public ID.
     */
    public function findByPublicId(string $publicId): ?ServerRegistry
    {
        $stmt = $this->getPdo()->prepare('SELECT * FROM server_registries WHERE public_id = :id LIMIT 1');
        $stmt->execute([':id' => $publicId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Return all registered servers.
     *
     * @return ServerRegistry[]
     */
    public function findAll(): array
    {
        $stmt = $this->getPdo()->query('SELECT * FROM server_registries ORDER BY updated_at DESC');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    /**
     * Remove a server by public ID.
     */
    public function remove(string $publicId): void
    {
        $stmt = $this->getPdo()->prepare('DELETE FROM server_registries WHERE public_id = :id');
        $stmt->execute([':id' => $publicId]);
    }

    /**
     * Delete servers that haven't sent a heartbeat within the threshold.
     *
     * @return int Number of deleted rows
     */
    public function cleanup(int $thresholdSeconds = 600): int
    {
        $cutoff = (new DateTimeImmutable())->modify("-{$thresholdSeconds} seconds");
        $stmt = $this->getPdo()->prepare('DELETE FROM server_registries WHERE last_heartbeat < :cutoff');
        $stmt->execute([':cutoff' => $cutoff->format(DateTimeImmutable::ATOM)]);

        return $stmt->rowCount();
    }

    /**
     * Ensure the SQLite schema exists.
     */
    private function getPdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new \PDO("sqlite:{$this->dbPath}");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec(self::SCHEMA);

        return $this->pdo;
    }

    /**
     * Hydrate a database row into a ServerRegistry model.
     */
    private function hydrate(array $row): ServerRegistry
    {
        return new ServerRegistry(
            publicId: $row['public_id'],
            url: $row['url'],
            name: $row['name'],
            version: $row['version'],
            lastHeartbeat: new DateTimeImmutable($row['last_heartbeat']),
            apiKey: $row['api_key'],
            createdAt: new DateTimeImmutable($row['created_at']),
        );
    }
}
