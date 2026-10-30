<?php

declare(strict_types=1);

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Infrastructure\Messaging\LibraryMessagePayloadCodec;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Metadata\Infrastructure\Messaging\MetadataMessagePayloadCodec;
use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Notification\Infrastructure\Messaging\NotificationMessagePayloadCodec;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Owns only the scanner, ingest and delivery chain of one capacity-fixture library. */
final readonly class ScanFixture
{
    private JsonMessageCodec $codec;
    private JsonTransportSerializer $transport;

    public function __construct(private Connection $connection, private ?RedisClientFactory $redis = null)
    {
        $this->codec = new JsonMessageCodec([new LibraryMessagePayloadCodec(), new MetadataMessagePayloadCodec(), new NotificationMessagePayloadCodec()]);
        $this->transport = new JsonTransportSerializer($this->codec);
    }

    /**
     * @param array<string, mixed> $manifest
     * @param Closure(): void $save
     * @return array<string, mixed>
     */
    public function prepare(array &$manifest, Closure $save): array
    {
        if (isset($manifest['scan'])) {
            throw new RuntimeException('This run already has scan preparation; retain its recorded ownership.');
        }
        $default = null;
        foreach ((new MetadataSettingDefinitions())->definitions() as $definition) {
            if ($definition->key === MetadataSettingDefinitions::AUTO_SYNC) {
                $default = $definition->default;
            }
        }
        $stored = $this->connection->fetchOne('SELECT value FROM system_settings WHERE key = ?', [MetadataSettingDefinitions::AUTO_SYNC]);
        $autoSync = $stored === false ? $default : json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        if ($autoSync !== false) {
            throw new RuntimeException('Scan qualification requires metadata.auto_sync=false; global settings are unchanged.');
        }
        if ((int) $this->connection->fetchOne("SELECT count(*) FROM webhooks WHERE category_filter IS NULL OR category_filter @> '[\"background_jobs\"]'::jsonb") !== 0) {
            throw new RuntimeException('Scan qualification requires no global/background_jobs webhooks; existing destinations are unchanged.');
        }
        $directory = $manifest['library']['path'] . '/scan-' . $manifest['run'];
        $path = $directory . '/track.wav';
        $sidecars = [dirname($manifest['song']['path']) . '/owned.lrc', $directory . '/track.lrc'];
        foreach ([$directory, ...$sidecars] as $ownedPath) {
            if (file_exists($ownedPath)) {
                throw new RuntimeException('Scan preparation would overwrite an existing path.');
            }
        }
        if ((int) $this->connection->fetchOne('SELECT count(*) FROM songs WHERE path = ?', [$path]) !== 0
            || (int) $this->connection->fetchOne('SELECT count(*) FROM library_file_index WHERE library_id = ? AND path = ?', [$manifest['library']['id'], $path]) !== 0) {
            throw new RuntimeException('Scan source must be absent from catalog and file index.');
        }
        $title = 'Live scanned ' . $manifest['run'];
        $album = $this->connection->fetchOne('SELECT title FROM albums WHERE id = ? AND library_id = ?', [$manifest['album']['id'], $manifest['library']['id']]);
        if (!is_string($album)) {
            throw new RuntimeException('Owned seed album is unavailable.');
        }
        $preferences = [];
        foreach ([...$manifest['users'], $manifest['observer']] as $user) {
            foreach (['email', 'push', 'webhook'] as $channel) {
                $preferences[] = ['id' => (string) new Uuid(), 'userId' => $user['id'], 'channel' => $channel];
            }
        }
        $wave = self::taggedWave($title, $album, $manifest['run']);
        $manifest['scan'] = ['title' => $title, 'path' => $path, 'hash' => hash('xxh3', $wave), 'files' => [$path, ...$sidecars], 'directories' => [$directory], 'preferences' => $preferences, 'history' => []];
        $save();
        if (!mkdir($directory, 0755) || !chmod($directory, 0755)) {
            throw new RuntimeException('Cannot create owned scan source directory.');
        }
        self::writeFile($path, $wave);
        foreach ($sidecars as $sidecar) {
            self::writeFile($sidecar, "[00:00.00]Live owned lyrics " . $manifest['run'] . "\n[00:30.00]Owned scan continuation\n");
        }
        $this->connection->transactional(function () use ($preferences): void {
            foreach ($preferences as $preference) {
                $this->connection->insert('notification_preferences', ['id' => $preference['id'], 'user_id' => $preference['userId'], 'category' => 'background_jobs', 'channel' => $preference['channel'], 'enabled' => 'false']);
            }
        });
        return ['title' => $title, 'path' => $path, 'hash' => $manifest['scan']['hash'], 'sidecars' => $sidecars, 'library' => $manifest['library'], 'manifestPath' => $manifest['directory'] . '/manifest.json'];
    }

    public static function taggedWave(string $title, string $album, string $run): string
    {
        $info = 'INFO';
        foreach (['INAM' => $title, 'IPRD' => $album, 'ICMT' => $run] as $tag => $value) {
            $value .= "\0";
            $info .= $tag . pack('V', strlen($value)) . $value . (strlen($value) % 2 ? "\0" : '');
        }
        $wave = LiveCapacityFixture::wave();
        $wave .= 'LIST' . pack('V', strlen($info)) . $info;
        return substr_replace($wave, pack('V', strlen($wave) - 8), 4, 4);
    }

    /**
     * @param array<string, mixed> $manifest
     * @param Closure(): void $save
     * @return array<string, mixed>
     */
    public function status(array &$manifest, Closure $save): array
    {
        if (!isset($manifest['scan'])) {
            throw new RuntimeException('Prepare this run for scanning first.');
        }
        $state = $this->databaseState($manifest);
        $beforeQueue = $state;
        $this->remember($manifest, $state);
        $save();
        $errors = [];
        $queue = [];
        try {
            $queue = $this->queueState($manifest);
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
        // A completed parent may have published a child while the queue was observed.
        $state = $this->databaseState($manifest);
        $this->remember($manifest, [...$state, 'queue' => $queue]);
        $save();
        $pending = $errors;
        if ($state !== $beforeQueue) {
            $pending[] = 'Owned asynchronous state changed during queue observation.';
        }
        if ($state['claim'] !== false && $state['claim']['claim_id'] !== null) {
            $pending[] = 'Owned library has an active claim.';
        }
        foreach ($state['jobs'] as $job) {
            if (!in_array($job['status'], ['finished', 'cancelled'], true)) {
                $pending[] = 'Job ' . $job['jobId'] . ' is ' . $job['status'] . '.';
            }
        }
        $terminalJobs = array_column(array_filter($manifest['scan']['history']['jobs'], static fn(array $job): bool => in_array($job['status'], ['finished', 'cancelled'], true)), 'jobId');
        foreach ($manifest['scan']['history']['queue'] as $message) {
            if (!in_array($message['jobId'], $terminalJobs, true)) {
                $pending[] = 'Observed owned delivery has no terminal monitor: ' . ($message['jobId'] ?? $message['id']) . '.';
            }
        }
        foreach (['events', 'deliveries'] as $key) {
            foreach ($state[$key] as $row) {
                if ($row['relayed_at'] === null || $row['dead_lettered_at'] !== null || $row['lease_token'] !== null) {
                    $pending[] = $key . ' ' . $row['id'] . ' is not safely relayed.';
                }
            }
        }
        foreach ($state['failures'] as $failure) {
            $pending[] = 'Retryable owned failure ' . $failure['id'] . '.';
        }
        foreach ($queue as $message) {
            $pending[] = 'Owned ' . $message['location'] . ' message ' . $message['id'] . ' remains.';
        }
        foreach ($state['unreadable'] as $id) {
            $pending[] = 'Unreadable owned job payload ' . $id . '.';
        }
        foreach ($state['albums'] as $album) {
            if ($album['cover_image_id'] !== null) {
                $pending[] = 'Unexpected artwork on owned album ' . $album['id'] . '.';
            }
        }
        $ingested = array_values(array_filter($state['songs'], static fn(array $song): bool => $song['path'] === $manifest['scan']['path'] && $song['title'] === $manifest['scan']['title'] && $song['hash'] === $manifest['scan']['hash']));
        return ['drained' => $pending === [], 'pending' => $pending, 'ingested' => $ingested, 'queue' => $queue, ...$state];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function databaseState(array $manifest): array
    {
        $library = $manifest['library']['id'];
        $albums = $this->connection->fetchAllAssociative('SELECT id, public_id, title, cover_image_id FROM albums WHERE library_id = ?', [$library]);
        $songs = $this->connection->fetchAllAssociative('SELECT s.id, s.public_id, s.title, s.path, s.hash FROM songs s JOIN albums a ON a.id = s.album_id WHERE a.library_id = ?', [$library]);
        $notifications = $this->connection->fetchAllAssociative("SELECT id, public_id, user_id FROM notifications WHERE event_type = 'library.scan_completed' AND reference_data->>'library_id' = ? AND user_id IN (?)", [$library, [...array_column($manifest['users'], 'id'), $manifest['observer']['id']]], [1 => ArrayParameterType::STRING]);
        $knownAlbums = array_values(array_unique([$manifest['album']['id'], ...array_column($manifest['albums'], 'id'), ...array_column($manifest['scan']['history']['albums'] ?? [], 'id'), ...array_column($albums, 'id')]));
        $knownNotifications = array_values(array_unique([...array_column($notifications, 'public_id'), ...array_column($manifest['scan']['history']['notifications'] ?? [], 'public_id')]));
        $events = $this->connection->fetchAllAssociative("SELECT id, event_name, payload, relayed_at, dead_lettered_at, lease_token FROM domain_event_outbox WHERE (event_name IN ('library.directory_indexed', 'library.scan_completed') AND payload->>'library_id' = ?) OR (event_name = 'album.created' AND payload->>'album_id' IN (?)) ORDER BY id", [$library, $knownAlbums], [1 => ArrayParameterType::STRING]);
        $deliveries = $knownNotifications === [] ? [] : $this->connection->fetchAllAssociative('SELECT id, channel, notification_id, relayed_at, dead_lettered_at, lease_token FROM domain_event_outbox_delivery WHERE notification_id IN (?) ORDER BY id', [$knownNotifications], [ArrayParameterType::STRING]);
        $tokens = [$library, $manifest['library']['slug'], ...$knownAlbums, ...$knownNotifications];
        $where = implode(' OR ', array_fill(0, count($tokens), 'data LIKE ?'));
        $jobs = [];
        $unreadable = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, job_id, data, status, attempt FROM job_monitors WHERE ' . $where, array_map(static fn(string $token): string => '%' . $token . '%', $tokens)) as $row) {
            try {
                $decoded = $this->codec->decode($row['data']);
                if ($this->owns($decoded->message, $manifest, $knownAlbums, $knownNotifications)) {
                    $document = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
                    $jobs[] = ['id' => $row['id'], 'jobId' => $row['job_id'], 'type' => $document['type'], 'payload' => $document['payload'], 'status' => $row['status'], 'attempt' => (int) $row['attempt']];
                }
            } catch (Throwable) {
                $unreadable[] = $row['id'];
            }
        }
        $failures = [];
        $where = str_replace('data LIKE', 'body LIKE', $where);
        foreach ($this->connection->fetchAllAssociative('SELECT id, body, headers FROM failed_messages WHERE ' . $where, array_map(static fn(string $token): string => '%' . $token . '%', $tokens)) as $row) {
            try {
                $envelope = $this->transport->decode(['body' => $row['body'], 'headers' => json_decode($row['headers'], true, flags: JSON_THROW_ON_ERROR)]);
                if ($this->owns($envelope->getMessage(), $manifest, $knownAlbums, $knownNotifications)) {
                    $failures[] = ['id' => $row['id']];
                }
            } catch (Throwable) {
                $unreadable[] = 'failure:' . $row['id'];
            }
        }
        return ['albums' => $albums, 'songs' => $songs, 'notifications' => $notifications, 'jobs' => $jobs, 'events' => $events, 'deliveries' => $deliveries, 'failures' => $failures, 'unreadable' => $unreadable, 'claim' => $this->connection->fetchAssociative('SELECT claim_id, claim_kind, scan_status FROM libraries WHERE id = ?', [$library])];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>>
     */
    private function queueState(array $manifest): array
    {
        if ($this->redis === null) {
            throw new RuntimeException('Owned scan queue cannot be observed without configured Redis.');
        }
        $albums = array_column($manifest['scan']['history']['albums'] ?? [], 'id');
        $notifications = array_column($manifest['scan']['history']['notifications'] ?? [], 'public_id');
        $tokens = [$manifest['library']['id'], $manifest['library']['slug'], ...$albums, ...$notifications];
        $deadline = microtime(true) + 5;
        return $this->redis->borrow(function (Redis $redis) use ($manifest, $albums, $notifications, $tokens, $deadline): array {
            $owned = [];
            $inspect = function (string $id, string $location, string $encoded) use (&$owned, $manifest, $albums, $notifications, $tokens): void {
                if (!array_any($tokens, static fn(string $token): bool => str_contains($encoded, $token))) {
                    return;
                }
                $outer = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
                $envelope = $this->transport->decode($outer);
                if ($this->owns($envelope->getMessage(), $manifest, $albums, $notifications)) {
                    $owned[] = ['id' => $id, 'location' => $location, 'jobId' => $envelope->last(JobIdStamp::class)?->jobId->toString()];
                }
            };
            $start = '-';
            do {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Owned scan queue observation exceeded five seconds; state retained.');
                }
                $rows = $redis->xRange('messages', $start, '+', 100);
                if ($rows === false) {
                    throw new RuntimeException('Redis stream observation failed.');
                }
                foreach ($rows as $id => $row) {
                    if (!is_string($row['message'] ?? null)) {
                        throw new RuntimeException('Redis stream contains an unreadable envelope.');
                    }
                    $inspect((string) $id, 'stream', $row['message']);
                }
                $last = array_key_last($rows);
                $start = '(' . $last;
            } while (count($rows) === 100);
            $cursor = null;
            do {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Owned scan delay observation exceeded five seconds; state retained.');
                }
                $redis->clearLastError();
                $rows = $redis->zScan('messages__queue', $cursor, null, 100);
                if ($rows === false && $redis->getLastError() !== null) {
                    throw new RuntimeException('Redis delay observation failed; state retained.');
                }
                foreach ($rows === false ? [] : $rows as $body => $score) {
                    $inspect(hash('sha256', $body), 'delay', $body);
                }
            } while ($cursor !== 0);
            return $owned;
        });
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $albums
     * @param list<string> $notifications
     */
    private function owns(object $message, array $manifest, array $albums, array $notifications): bool
    {
        return match (true) {
            $message instanceof ScanLibraryCommand => $message->getLibrarySlug()->toString() === $manifest['library']['slug'],
            $message instanceof FilesDiscovered => $message->libraryId->toString() === $manifest['library']['id'],
            $message instanceof ExtractAlbumCoverCommand => in_array($message->getAlbumId()->toString(), $albums, true),
            $message instanceof SendEmailCommand, $message instanceof SendPushCommand, $message instanceof SendWebhookCommand => in_array($message->userId->toString(), [...array_column($manifest['users'], 'id'), $manifest['observer']['id']], true) && in_array($message->notificationPublicId, $notifications, true),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $state
     */
    private function remember(array &$manifest, array $state): void
    {
        foreach (['albums', 'songs', 'notifications', 'jobs', 'events', 'deliveries', 'queue'] as $key) {
            $rows = [];
            foreach ([...($manifest['scan']['history'][$key] ?? []), ...($state[$key] ?? [])] as $row) {
                $rows[($row['location'] ?? '') . ':' . $row['id']] = $row;
            }
            $manifest['scan']['history'][$key] = array_values($rows);
        }
    }

    /** @param array<string, mixed> $manifest */
    public function deleteTerminalRows(array $manifest): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('Scan cleanup row locks require the owning cleanup transaction.');
        }
        foreach (['jobs' => 'job_monitors', 'deliveries' => 'domain_event_outbox_delivery', 'events' => 'domain_event_outbox'] as $key => $table) {
            $ids = array_column($manifest['scan']['history'][$key] ?? [], 'id');
            if ($ids === []) {
                continue;
            }
            $locked = $this->connection->fetchFirstColumn("SELECT id FROM $table WHERE id IN (?) ORDER BY id FOR UPDATE NOWAIT", [$ids], [ArrayParameterType::STRING]);
            $safe = $key === 'jobs' ? "status IN ('finished', 'cancelled')" : 'relayed_at IS NOT NULL AND dead_lettered_at IS NULL AND lease_token IS NULL';
            if ((int) $this->connection->fetchOne("SELECT count(*) FROM $table WHERE id IN (?) AND NOT ($safe)", [$ids], [ArrayParameterType::STRING]) !== 0) {
                throw new RuntimeException('Owned asynchronous state changed before cleanup; manifest retained.');
            }
            $deleted = $this->connection->executeStatement("DELETE FROM $table WHERE id IN (?) AND ($safe)", [$ids], [ArrayParameterType::STRING]);
            if ($deleted !== count($locked)) {
                throw new RuntimeException('Owned asynchronous rows changed during cleanup; manifest retained.');
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    public function removeFiles(array $manifest): void
    {
        foreach ($manifest['scan']['files'] as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Cannot remove recorded scan source; manifest retained.');
            }
        }
        foreach ($manifest['scan']['directories'] as $directory) {
            if (is_dir($directory) && !rmdir($directory)) {
                throw new RuntimeException('Recorded scan directory is not empty; manifest retained.');
            }
        }
    }

    private static function writeFile(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, 0644)) {
            throw new RuntimeException('Cannot write recorded worker-readable scan source.');
        }
    }
}
