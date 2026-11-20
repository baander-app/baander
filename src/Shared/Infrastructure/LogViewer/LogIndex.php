<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\LogViewer\LogQuery;

final class LogIndex
{
    private readonly \SQLite3 $db;
    private bool $mergeInProgress = false;
    private int $retentionRetryAfter = 0;

    public function __construct(private readonly string $path, bool $readOnly = false, private readonly int $maxStorageBytes = 1073741824)
    {
        $this->db = new \SQLite3($path, $readOnly ? SQLITE3_OPEN_READONLY : SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(3000);
        if (!$readOnly) {
            $this->db->exec('PRAGMA auto_vacuum=INCREMENTAL');
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->db->exec('PRAGMA synchronous=NORMAL');
            $this->db->exec("CREATE TABLE IF NOT EXISTS sources (id TEXT PRIMARY KEY, payload TEXT NOT NULL, checkpoint TEXT);
                CREATE TABLE IF NOT EXISTS metadata (name TEXT PRIMARY KEY, value INTEGER NOT NULL);
                INSERT OR IGNORE INTO metadata VALUES ('retention_generation', 0);
                CREATE TABLE IF NOT EXISTS logs (seq INTEGER PRIMARY KEY AUTOINCREMENT, id TEXT NOT NULL UNIQUE, source_id TEXT NOT NULL, generation TEXT NOT NULL, source_sequence INTEGER NOT NULL, timestamp TEXT NOT NULL, level TEXT NOT NULL, channel TEXT, message TEXT NOT NULL, context TEXT NOT NULL, extra TEXT NOT NULL, raw TEXT NOT NULL, correlation_id TEXT, node TEXT, service TEXT, stream TEXT, truncated INTEGER NOT NULL, payload TEXT NOT NULL, UNIQUE(source_id,generation,source_sequence));
                CREATE INDEX IF NOT EXISTS logs_time ON logs(timestamp,seq);
                CREATE INDEX IF NOT EXISTS logs_source_time ON logs(source_id,timestamp,seq);
                CREATE INDEX IF NOT EXISTS logs_channel ON logs(channel);
                CREATE VIRTUAL TABLE IF NOT EXISTS logs_fts USING fts5(message,raw,content=logs,content_rowid=seq,tokenize='trigram case_sensitive 1');
                CREATE TRIGGER IF NOT EXISTS logs_insert AFTER INSERT ON logs BEGIN INSERT INTO logs_fts(rowid,message,raw) VALUES(new.seq,new.message,new.raw); END;
                CREATE TRIGGER IF NOT EXISTS logs_delete AFTER DELETE ON logs BEGIN INSERT INTO logs_fts(logs_fts,rowid,message,raw) VALUES('delete',old.seq,old.message,old.raw); END;");
        }
    }

    public static function sourceId(string $identity): string
    {
        return hash('sha256', $identity);
    }

    /** @param array<string, mixed> $source */
    public function upsertSource(array $source): void
    {
        if (!is_string($source['id'] ?? null) || $source['id'] === '') {
            throw new InvalidInputException('A log source requires an opaque ID.');
        }
        $previous = $this->execute('SELECT payload FROM sources WHERE id=:id', ['id' => $source['id']])->fetchArray(SQLITE3_ASSOC);
        if ($previous !== false) {
            $source += json_decode($previous['payload'], true, 512, JSON_THROW_ON_ERROR);
        }
        $source += ['name' => $source['id'], 'node' => null, 'service' => null, 'kind' => 'file', 'format' => 'raw', 'size' => 0, 'status' => 'catching-up', 'lastCollectedAt' => null, 'retainedFrom' => null, 'issues' => []];
        $this->execute('INSERT INTO sources(id,payload) VALUES (:id,:payload) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload', ['id' => $source['id'], 'payload' => json_encode($source, JSON_THROW_ON_ERROR)]);
    }

    /** @return array<string, mixed> */
    public function sources(): array
    {
        $items = [];
        $result = $this->db->query('SELECT payload FROM sources ORDER BY id');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $items[] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        }
        $channels = [];
        $channelResult = $this->db->query("SELECT DISTINCT channel FROM logs WHERE channel IS NOT NULL AND channel <> '' ORDER BY channel");
        while ($row = $channelResult->fetchArray(SQLITE3_ASSOC)) {
            $channels[] = $row['channel'];
        }
        return ['items' => $items, 'nodes' => [], 'coverage' => $this->coverage($items), 'channels' => $channels];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed>|null $checkpoint
     */
    public function append(string $sourceId, string $generation, int $sequence, array $record, ?array $checkpoint = null): string
    {
        if ($this->path !== ':memory:' && $this->storageBytes() > $this->maxStorageBytes) {
            if (hrtime(true) < $this->retentionRetryAfter) {
                throw new LogStorageLimitException('Log storage limit reached; ingestion is suspended while bounded reclamation is pending.');
            }
            $this->pruneToSize($this->maxStorageBytes);
        }
        $id = hash('sha256', json_encode([$sourceId, $generation, $sequence], JSON_THROW_ON_ERROR));
        $record['id'] = $id;
        $record['sourceId'] = $sourceId;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $parameters = ['id' => $id, 'source_id' => $sourceId, 'generation' => $generation, 'source_sequence' => $sequence, 'payload' => json_encode($record, JSON_THROW_ON_ERROR)];
            foreach (['timestamp', 'level', 'channel', 'message', 'raw', 'node', 'service', 'stream', 'truncated'] as $field) {
                $parameters[$field] = $record[$field] ?? ($field === 'truncated' ? false : null);
            }
            $parameters['correlation_id'] = $record['correlationId'] ?? null;
            $parameters['context'] = json_encode($record['context'] ?? [], JSON_THROW_ON_ERROR);
            $parameters['extra'] = json_encode($record['extra'] ?? [], JSON_THROW_ON_ERROR);
            $columns = implode(',', array_keys($parameters));
            $values = ':' . implode(',:', array_keys($parameters));
            $this->execute("INSERT OR IGNORE INTO logs ($columns) VALUES ($values)", $parameters);
            if ($checkpoint !== null) {
                $this->execute('UPDATE sources SET checkpoint=:checkpoint WHERE id=:id', ['id' => $sourceId, 'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR)]);
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $exception) {
            $this->db->exec('ROLLBACK');
            throw $exception;
        }
        if ($this->path !== ':memory:' && $this->storageBytes() > $this->maxStorageBytes) {
            $this->pruneToSize($this->maxStorageBytes);
        }
        return $id;
    }

    /** @param array<string, mixed> $checkpoint */
    public function saveCheckpoint(string $sourceId, array $checkpoint): void
    {
        $this->execute('UPDATE sources SET checkpoint=:checkpoint WHERE id=:id', ['id' => $sourceId, 'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR)]);
        if ($this->db->changes() !== 1) {
            throw new InvalidInputException('Cannot save a checkpoint for an unknown log source.');
        }
    }

    /** @return array<string, mixed>|null */
    public function checkpoint(string $sourceId): ?array
    {
        $row = $this->execute('SELECT checkpoint FROM sources WHERE id=:id', ['id' => $sourceId])->fetchArray(SQLITE3_ASSOC);
        return $row && $row['checkpoint'] !== null ? json_decode($row['checkpoint'], true, 512, JSON_THROW_ON_ERROR) : null;
    }

    /** @return array<string, mixed> */
    public function detail(string $id): array
    {
        $row = $this->execute('SELECT payload FROM logs WHERE id=:id', ['id' => $id])->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            throw new InvalidInputException('Log record is unavailable or retention expired.');
        }
        return json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function query(LogQuery $query): array
    {
        $this->db->exec('BEGIN');
        try {
            return $this->querySnapshot($query);
        } finally {
            $this->db->exec('COMMIT');
        }
    }

    /** @return array<string, mixed> */
    private function querySnapshot(LogQuery $query): array
    {
        $generation = (int) $this->db->querySingle("SELECT value FROM metadata WHERE name='retention_generation'");
        $snapshot = ['highWater' => (int) $this->db->querySingle('SELECT COALESCE(MAX(seq),0) FROM logs'), 'retentionGeneration' => $generation, 'filter' => $query->fingerprint()];
        $cursor = null;
        if ($query->cursor !== null) {
            try {
                $cursor = json_decode(base64_decode($query->cursor, true) ?: '', true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InvalidInputException('Invalid log cursor.');
            }
            if (!is_array($cursor) || !is_array($cursor['snapshot'] ?? null) || ($cursor['snapshot']['filter'] ?? null) !== $query->fingerprint() || ($cursor['snapshot']['retentionGeneration'] ?? null) !== $generation || !is_int($cursor['snapshot']['highWater'] ?? null) || !is_int($cursor['seq'] ?? null) || !is_string($cursor['timestamp'] ?? null)) {
                throw new InvalidInputException('Log cursor filters changed or retention expired.');
            }
            $snapshot = $cursor['snapshot'];
        }
        $where = ['seq <= :high'];
        $params = ['high' => $snapshot['highWater']];
        foreach (['sources' => 'source_id', 'levels' => 'level', 'channels' => 'channel'] as $property => $column) {
            if ($query->$property !== []) {
                $placeholders = [];
                foreach ($query->$property as $key => $value) {
                    $name = $property . $key;
                    $params[$name] = $value;
                    $placeholders[] = ':' . $name;
                }
                $where[] = $column . ' IN (' . implode(',', $placeholders) . ')';
            }
        }
        foreach (['correlationId' => 'correlation_id'] as $property => $column) {
            if ($query->$property !== null) {
                $where[] = $column . '=:' . $property;
                $params[$property] = $query->$property;
            }
        }
        foreach (['since' => '>=', 'until' => '<='] as $property => $operator) {
            if ($query->$property !== null) {
                $where[] = 'timestamp ' . $operator . ' :' . $property;
                $params[$property] = (new \DateTimeImmutable($query->$property))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            }
        }
        // FTS is only a candidate accelerator; retained strings remain the authority.
        if (mb_strlen($query->search, 'UTF-8') >= 3 && preg_match('/^[\p{L}\p{N} ]+$/uD', $query->search) === 1) {
            $where[] = 'seq IN (SELECT rowid FROM logs_fts WHERE logs_fts MATCH :literal)';
            $params['literal'] = '"' . str_replace('"', '""', $query->search) . '"';
        }
        if ($cursor !== null) {
            $operator = $query->order === 'asc' ? '>' : '<';
            $where[] = "(timestamp,seq) $operator (:time,:seq)";
            $params['time'] = $cursor['timestamp'];
            $params['seq'] = $cursor['seq'];
        }
        $direction = $query->order === 'asc' ? 'ASC' : 'DESC';
        $result = $this->execute('SELECT seq,timestamp,payload,message,raw FROM logs WHERE ' . implode(' AND ', $where) . " ORDER BY timestamp $direction,seq $direction LIMIT 10001", $params);
        $items = [];
        $last = null;
        $scanned = 0;
        $more = false;
        $budget = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($scanned >= 10000) {
                $budget = true;
                $more = true;
                break;
            }
            $matches = $query->search === '' || str_contains($row['message'], $query->search) || str_contains($row['raw'], $query->search);
            if ($matches && count($items) >= $query->limit) {
                $more = true;
                break;
            }
            ++$scanned;
            $last = $row;
            if ($matches) {
                $items[] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        $selectedSources = $this->sources()['items'];
        if ($query->sources !== []) {
            $selectedSources = array_values(array_filter($selectedSources, static fn(array $source): bool => in_array($source['id'], $query->sources, true)));
        }
        $coverage = $this->coverage($selectedSources);
        foreach (array_diff($query->sources, array_column($selectedSources, 'id')) as $missing) {
            $coverage['complete'] = false;
            $coverage['issues'][] = $missing . ': source unavailable';
        }
        foreach ($selectedSources as $source) {
            if ($query->since !== null && is_string($source['retainedFrom']) && strtotime($query->since) < strtotime($source['retainedFrom'])) {
                $coverage['complete'] = false;
                $coverage['issues'][] = $source['id'] . ': requested history predates retained records';
            }
        }
        if ($budget) {
            $coverage['complete'] = false;
            $coverage['issues'][] = 'Literal search scan budget reached; continue to search the remaining snapshot.';
        }
        return ['items' => $items, 'nextCursor' => $more && $last !== null ? base64_encode(json_encode(['snapshot' => $snapshot, 'timestamp' => $last['timestamp'], 'seq' => $last['seq']], JSON_THROW_ON_ERROR)) : null, 'snapshot' => $snapshot, 'coverage' => $coverage];
    }

    public function prune(string $before): int
    {
        $before = (new \DateTimeImmutable($before))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $deleted = 0;
        for ($batch = 0; $batch < 4096; ++$batch) {
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $this->execute('DELETE FROM logs WHERE seq IN (SELECT seq FROM logs WHERE timestamp < :before ORDER BY timestamp,seq LIMIT 256)', ['before' => $before]);
                $count = $this->db->changes();
                if ($count > 0) {
                    $this->recordRetentionBoundary($before);
                }
                $this->db->exec('COMMIT');
            } catch (\Throwable $exception) {
                $this->db->exec('ROLLBACK');
                throw $exception;
            }
            $deleted += $count;
            if ($this->path !== ':memory:' && $this->storageBytes() > $this->maxStorageBytes) {
                $this->pruneToSize($this->maxStorageBytes);
            }
            if ($count < 256) {
                break;
            }
        }
        return $deleted;
    }

    /** Includes the database, full-text index, WAL and shared-memory files for disk indexes. */
    public function storageBytes(): int
    {
        if ($this->path === ':memory:') {
            return (int) $this->db->querySingle('SELECT COALESCE(SUM(length(CAST(payload AS BLOB))),0) FROM logs');
        }
        $bytes = 0;
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $file) {
            clearstatcache(true, $file);
            if (is_file($file)) {
                $bytes += filesize($file);
            }
        }
        return $bytes;
    }

    /** Reclaims storage in bounded transactions; blocked reclamation stops ingestion explicitly. */
    public function pruneToSize(int $bytes): int
    {
        if ($bytes < 0) {
            throw new InvalidInputException('Retention byte budget cannot be negative.');
        }
        if ($this->storageBytes() <= $bytes) {
            return 0;
        }
        $deleted = 0;
        if ($this->path !== ':memory:') {
            $this->reclaimStorage();
        }
        for ($batch = 0; $this->storageBytes() > $bytes && $batch < 4; ++$batch) {
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $this->db->exec('DELETE FROM logs WHERE seq IN (SELECT seq FROM logs ORDER BY timestamp,seq LIMIT 64)');
                $count = $this->db->changes();
                if ($count > 0) {
                    $this->recordRetentionBoundary((new \DateTimeImmutable())->format(DATE_ATOM));
                }
                $this->db->exec('COMMIT');
            } catch (\Throwable $exception) {
                $this->db->exec('ROLLBACK');
                throw $exception;
            }
            $deleted += $count;
            if ($this->path !== ':memory:') {
                $this->reclaimStorage(true);
            }
            if ($count === 0) {
                break;
            }
        }
        if ($this->storageBytes() > $bytes) {
            $this->retentionRetryAfter = hrtime(true) + 1000000000;
            throw new LogStorageLimitException('Log storage limit reached; ingestion is suspended until index storage can be reclaimed.');
        }
        return $deleted;
    }

    private function reclaimStorage(bool $merge = false): void
    {
        // An active reader must pause collection rather than hold every source behind a busy wait.
        $this->db->busyTimeout(0);
        try {
            $this->checkpointStorage();
            if ($merge) {
                $before = (int) $this->db->querySingle('SELECT total_changes()');
                $pages = $this->mergeInProgress ? 64 : -64;
                $this->execute("INSERT INTO logs_fts(logs_fts,rank) VALUES('merge',:pages)", ['pages' => $pages]);
                $this->mergeInProgress = (int) $this->db->querySingle('SELECT total_changes()') - $before >= 2;
            }
            $this->db->exec('PRAGMA incremental_vacuum(128)');
            $this->checkpointStorage();
        } catch (\Throwable $exception) {
            $this->retentionRetryAfter = hrtime(true) + 1000000000;
            throw $exception;
        } finally {
            $this->db->busyTimeout(3000);
        }
    }

    private function checkpointStorage(): void
    {
        $result = $this->db->query('PRAGMA wal_checkpoint(TRUNCATE)');
        $checkpoint = $result->fetchArray(SQLITE3_NUM);
        $result->finalize();
        if ($checkpoint === false || $checkpoint[0] !== 0) {
            throw new LogStorageLimitException('Log storage limit: an active reader prevents WAL reclamation; ingestion is suspended.');
        }
    }

    private function recordRetentionBoundary(string $fallback): void
    {
        $this->db->exec("UPDATE metadata SET value=value+1 WHERE name='retention_generation'");
        foreach ($this->sources()['items'] as $source) {
            $row = $this->execute('SELECT MIN(timestamp) AS oldest FROM logs WHERE source_id=:source', ['source' => $source['id']])->fetchArray(SQLITE3_ASSOC);
            $source['retainedFrom'] = $row['oldest'] ?? $fallback;
            $this->upsertSource($source);
        }
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @return array{complete:bool,issues:list<string>}
     */
    private function coverage(array $sources): array
    {
        $issues = [];
        foreach ($sources as $source) {
            foreach ($source['issues'] as $issue) {
                $issues[] = $source['id'] . ': ' . (is_string($issue) ? $issue : json_encode($issue, JSON_THROW_ON_ERROR));
            }
            if (!in_array($source['status'], ['following', 'archived', 'ready', 'healthy'], true)) {
                $issues[] = $source['id'] . ': ' . $source['status'];
            }
        }
        return ['complete' => $issues === [], 'issues' => $issues];
    }

    /** @param array<string, mixed> $parameters */
    private function execute(string $sql, array $parameters): \SQLite3Result
    {
        $statement = $this->db->prepare($sql);
        foreach ($parameters as $name => $value) {
            $type = $value === null ? SQLITE3_NULL : (is_int($value) || is_bool($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
            $statement->bindValue(':' . $name, $value, $type);
        }
        return $statement->execute();
    }
}
