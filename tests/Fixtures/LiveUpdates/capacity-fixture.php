<?php

declare(strict_types=1);

use App\Auth\Infrastructure\Security\User\PasswordHasher;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once __DIR__ . '/ScanFixture.php';

/** Scoped producer for real preview qualification; importing it never opens a connection. */
final class LiveCapacityFixture
{
    /** @var array<string, mixed> */
    private array $manifest = [];
    private bool $projectorHeld = false;
    private readonly ScanFixture $scan;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $root,
        private readonly ?string $mediaRoot = null,
        ?RedisClientFactory $redis = null,
    ) {
        $this->scan = new ScanFixture($connection, $redis);
    }

    /**
     * @param array<string, mixed> $command
     * @param (Closure(array<string, mixed>): void)|null $progress
     * @return array<string, mixed>
     */
    public function execute(array $command, ?Closure $progress = null): array
    {
        $run = $command['run'] ?? bin2hex(random_bytes(10));
        if (!is_string($run) || preg_match('/\A[a-z0-9-]{1,48}\z/D', $run) !== 1) {
            throw new InvalidArgumentException('Invalid fixture run.');
        }
        $op = $command['op'] ?? '';
        $directory = $this->root . '/' . $run;
        if ($op === 'create') {
            return $this->create($run, $directory);
        }
        $this->manifest = json_decode((string) file_get_contents($directory . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        return match ($op) {
            'ordinary' => $this->produce(32, 0, (int) ($command['sequence'] ?? 1), $progress),
            'observer-notification' => $this->produce(1, 0, (int) ($command['sequence'] ?? 1), $progress, [$this->manifest['observer']]),
            'burst' => $this->produce((int) ($command['count'] ?? 10_000), (int) ($command['durationMs'] ?? 10_000), (int) ($command['sequence'] ?? 1), $progress),
            'catalog' => $this->catalog((int) ($command['albums'] ?? 10_000), (int) ($command['songs'] ?? 1000)),
            'change' => $this->change($command),
            'authority' => $this->authority($command),
            'projector' => $this->projector((bool) ($command['hold'] ?? false)),
            'sample' => $this->sample(),
            'prepare-scan' => $this->scan->prepare($this->manifest, $this->save(...)),
            'scan-status' => $this->scan->status($this->manifest, $this->save(...)),
            'cleanup' => $this->cleanup((int) ($command['waitMs'] ?? 30_000)),
            default => throw new InvalidArgumentException('Unknown fixture operation.'),
        };
    }

    public function close(): void
    {
        try {
            if ($this->projectorHeld) {
                $this->projector(false);
            }
        } finally {
            $this->connection->close();
            $this->projectorHeld = false;
        }
    }

    public static function wave(): string
    {
        // Sixty seconds, mono PCM, 8 kHz: enough for a real playback continuity check.
        $samples = str_repeat(pack('v', 0), 8000 * 60);
        return 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16_000, 2, 16)
            . 'data' . pack('V', strlen($samples)) . $samples;
    }

    /**
     * @return array<string, mixed>
     */
    private function create(string $run, string $directory): array
    {
        $mediaDirectory = ($this->mediaRoot ?? $this->root) . '/' . $run;
        if (is_dir($directory)) {
            throw new RuntimeException('Run already exists; use its manifest to clean it first.');
        }
        if ($mediaDirectory !== $directory && file_exists($mediaDirectory)) {
            throw new RuntimeException('Owned media leaf already exists; choose a new run.');
        }
        if (!mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create private fixture manifest directory.');
        }
        $password = bin2hex(random_bytes(18));
        $users = [];
        for ($i = 0; $i < 33; ++$i) {
            $users[] = $this->identity() + ['email' => "live-$run-$i@baander.app", 'password' => $password, 'admin' => $i === 32];
        }
        $this->manifest = [
            'run' => $run, 'directory' => $directory, 'mediaDirectory' => $mediaDirectory,
            'users' => array_slice($users, 0, 32), 'observer' => $users[32],
            'library' => $this->identity() + ['slug' => 'live-' . $run, 'path' => $mediaDirectory],
            'playlist' => $this->identity(), 'album' => $this->identity(),
            'song' => $this->identity() + ['path' => $mediaDirectory . '/owned.wav'],
            'fileIndex' => ['id' => (string) new Uuid()],
            'job' => $this->identity(), 'albums' => [], 'songs' => [], 'notifications' => [],
            'collaborators' => [$this->identity(), $this->identity()], 'playlistSong' => $this->identity(),
        ];
        // Save ownership before any database write. Failed or interrupted runs remain recoverable.
        $this->save();
        if ($mediaDirectory !== $directory && (!mkdir($mediaDirectory, 0755, true) || !chmod($mediaDirectory, 0755))) {
            throw new RuntimeException('Fixture media needs app-writable MEDIA_STORAGE_PATH shared with source workers.');
        }
        $wave = self::wave();
        if (file_put_contents($this->manifest['song']['path'], $wave) !== strlen($wave) || !chmod($this->manifest['song']['path'], 0644)) {
            throw new RuntimeException('Cannot write worker-readable owned WAV in MEDIA_STORAGE_PATH.');
        }
        $hash = hash_file('xxh3', $this->manifest['song']['path']);
        if (!is_string($hash)) {
            throw new RuntimeException('Cannot hash the owned source observation.');
        }
        $this->manifest['song']['hash'] = $hash;
        $this->manifest['fileIndex'] += ['path' => $this->manifest['song']['path'], 'hash' => $hash, 'size' => filesize($this->manifest['song']['path']), 'modifiedAt' => filemtime($this->manifest['song']['path'])];
        $this->save();
        $configuration = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/security.yaml');
        $hasher = new PasswordHasher(new PasswordHasherFactory($configuration['security']['password_hashers']));
        $hash = $hasher->hash($password);
        if (!$hasher->verify($password, $hash)) {
            throw new RuntimeException('Configured password hash did not verify.');
        }
        $this->connection->transactional(function () use ($users, $hash): void {
            foreach ($users as $user) {
                $this->insert('users', $user, [
                    'name' => 'Live ' . $this->manifest['run'], 'email' => $user['email'], 'password' => $hash,
                    'totp_secret' => '',
                    'roles' => json_encode($user['admin'] ? ['ROLE_ADMIN', 'ROLE_USER'] : ['ROLE_USER'], JSON_THROW_ON_ERROR),
                    'email_verified_at' => $this->now(),
                ]);
            }
            $this->insert('libraries', $this->manifest['library'], ['name' => 'Live ' . $this->manifest['run'], 'slug' => $this->manifest['library']['slug'], 'path' => $this->manifest['library']['path'], 'type' => 'music']);
            foreach ($users as $user) {
                $this->connection->insert('user_library_access', ['user_id' => $user['id'], 'library_id' => $this->manifest['library']['id']]);
            }
            $this->insertAlbum($this->manifest['album'], 0);
            $this->insertSong($this->manifest['song'], 0);
            $source = $this->manifest['fileIndex'];
            $this->connection->insert('library_file_index', ['id' => $source['id'], 'library_id' => $this->manifest['library']['id'], 'path' => $source['path'], 'hash' => $source['hash'], 'size' => $source['size'], 'extension' => 'wav', 'modified_at' => $source['modifiedAt'], 'discovered_at' => $this->now(), 'available' => 'true']);
            $this->insert('playlists', $this->manifest['playlist'], ['user_id' => $users[0]['id'], 'name' => 'Live playlist ' . $this->manifest['run'], 'is_collaborative' => true]);
            foreach ([1, 2] as $index) {
                $this->connection->insert('playlist_collaborators', ['id' => $this->manifest['collaborators'][$index - 1]['id'], 'playlist_id' => $this->manifest['playlist']['id'], 'user_id' => $users[$index]['id'], 'role' => 'editor', 'created_at' => $this->now(), 'updated_at' => $this->now()]);
            }
            $this->connection->insert('playlist_song', ['id' => $this->manifest['playlistSong']['id'], 'playlist_id' => $this->manifest['playlist']['id'], 'song_id' => $this->manifest['song']['id'], 'position' => 0, 'created_at' => $this->now(), 'updated_at' => $this->now()]);
            $this->connection->insert('job_monitors', ['id' => $this->manifest['job']['id'], 'job_id' => 'live-' . $this->manifest['run'], 'name' => 'Live qualification', 'queue' => 'fixture', 'status' => 'queued', 'created_at' => $this->now(), 'updated_at' => $this->now()]);
        });
        return $this->manifest;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(int $albums, int $songs): array
    {
        if ($albums < 1 || $songs < 1 || $this->manifest['albums'] !== [] || $this->manifest['songs'] !== []) {
            throw new InvalidArgumentException('Catalog needs positive totals and may be populated once.');
        }
        for ($i = 1; $i < $albums; ++$i) {
            $this->manifest['albums'][] = $this->identity();
        }
        for ($i = 1; $i < $songs; ++$i) {
            $this->manifest['songs'][] = $this->identity();
        }
        $this->save();
        $start = microtime(true) * 1000;
        $this->connection->transactional(function (): void {
            foreach ($this->manifest['albums'] as $i => $album) {
                $this->insertAlbum($album, $i + 1);
            }
            foreach ($this->manifest['songs'] as $i => $song) {
                $this->insertSong($song, $i + 1);
            }
        });
        return ['albums' => $albums, 'songs' => $songs, 'library' => $this->manifest['library'], 'album' => $this->manifest['album'], 'song' => $this->manifest['song'], 'commitStartEpochMs' => $start, 'commitEndEpochMs' => microtime(true) * 1000];
    }

    /**
     * @param (Closure(array<string, mixed>): void)|null $progress
     * @param list<array{id: string}>|null $recipients
     * @return array<string, mixed>
     */
    private function produce(int $count, int $durationMs, int $sequence, ?Closure $progress, ?array $recipients = null): array
    {
        $recipients ??= $this->manifest['users'];
        if ($count < 1 || $durationMs < 0) {
            throw new InvalidArgumentException('Count must be positive and duration nonnegative.');
        }
        $notifications = [];
        for ($i = 0; $i < $count; ++$i) {
            $notifications[] = $this->identity();
        }
        $this->manifest['notifications'] = [...$this->manifest['notifications'], ...$notifications];
        $this->save();
        $expected = $this->notificationState($recipients);
        $started = microtime(true) * 1000;
        $captured = 0;
        $first = null;
        $last = null;
        for ($offset = 0; $offset < $count; $offset += 100) {
            $batchSize = min(100, $count - $offset);
            $deadline = $started + ($offset + $batchSize) / $count * $durationMs;
            $sleep = $deadline - microtime(true) * 1000;
            if ($sleep > 0) {
                usleep((int) ($sleep * 1000));
            }
            $this->connection->beginTransaction();
            try {
                for ($i = $offset; $i < $offset + $batchSize; ++$i) {
                    $recipientIndex = $i % count($recipients);
                    $user = $recipients[$recipientIndex];
                    $title = 'Live ' . $this->manifest['run'] . " $sequence:$i";
                    $this->connection->insert('notifications', [
                        'id' => $notifications[$i]['id'], 'public_id' => $notifications[$i]['publicId'], 'user_id' => $user['id'],
                        'category' => 'background_jobs', 'event_type' => 'live-qualification', 'title' => $title, 'body' => $title,
                        // Monotonic timestamp per row gives deterministic newest-row ordering.
                        'created_at' => (new DateTimeImmutable())->modify('+' . $i . ' microseconds')->format('Y-m-d H:i:s.uP'),
                    ]);
                    ++$expected[$recipientIndex]['count'];
                    $expected[$recipientIndex]['title'] = $title;
                }
                // Concurrent projector may remove older rows, so count only this transaction's xmin.
                $actual = (int) $this->connection->fetchOne('SELECT count(*) FROM query_change_inbox WHERE xmin::text = pg_current_xact_id()::text');
                if ($actual !== $batchSize) {
                    throw new RuntimeException("Expected $batchSize captures, observed $actual.");
                }
                $commitStart = microtime(true) * 1000;
                $this->connection->commit();
                $last = ['commitStartEpochMs' => $commitStart, 'commitEndEpochMs' => microtime(true) * 1000];
                $first ??= $last;
                $captured += $actual;
            } catch (Throwable $error) {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
                throw $error;
            }
            if ($progress !== null && $durationMs > 0) {
                $progress(['inserted' => min($offset + $batchSize, $count), 'capturedRows' => $captured, 'users' => $expected, ...$last, 'sample' => $this->sample()]);
            }
        }
        $observed = $this->notificationState($recipients);
        if ($expected !== $observed) {
            throw new RuntimeException('Committed notification counts or newest markers differ from producer expectations.');
        }
        return ['inserted' => $count, 'capturedRows' => $captured, 'producerStartedEpochMs' => $started, 'durationMs' => microtime(true) * 1000 - $started, 'plannedDurationMs' => $durationMs, 'firstCommit' => $first, ...$last, 'users' => $observed, 'producerErrors' => [], 'sample' => $this->sample()];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function change(array $command): array
    {
        $target = $command['target'] ?? 'album';
        $value = (string) ($command['value'] ?? 'Changed ' . bin2hex(random_bytes(4)));
        $start = microtime(true) * 1000;
        $result = $this->connection->transactional(function () use ($target, $value, $command): array {
            if ($target === 'notification') {
                $id = $this->connection->fetchOne('SELECT id FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1', [$this->manifest['users'][(int) ($command['userIndex'] ?? 0)]['id']]);
                if (!$id) {
                    throw new RuntimeException('Create notifications with ordinary first.');
                }
                $this->connection->update('notifications', ['is_read' => true], ['id' => $id]);
                return ['id' => $id, 'isRead' => true];
            }
            [$table, $field] = match ($target) {
                'album' => ['albums', 'title'],
                'playlist' => ['playlists', 'name'],
                'job' => ['job_monitors', 'status'],
                default => throw new InvalidArgumentException('Unknown change target.'),
            };
            $data = [$field => $value, 'updated_at' => $this->now()];
            $this->connection->update($table, $data, ['id' => $this->manifest[$target]['id']]);
            return $this->manifest[$target] + $data;
        });
        return ['target' => $target, 'value' => $value, 'entity' => $result, 'commitStartEpochMs' => $start, 'commitEndEpochMs' => microtime(true) * 1000];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function authority(array $command): array
    {
        $index = (int) ($command['userIndex'] ?? 1);
        $user = $this->manifest['users'][$index] ?? throw new InvalidArgumentException('Unknown owned user.');
        $grant = (bool) ($command['grant'] ?? false);
        $target = $command['target'] ?? 'library';
        $start = microtime(true) * 1000;
        $this->connection->transactional(function () use ($target, $user, $grant): void {
            if ($target === 'account') {
                $this->connection->update('users', ['disabled' => !$grant, 'updated_at' => $this->now()], ['id' => $user['id']]);
                return;
            }
            if ($target === 'library') {
                $this->connection->delete('user_library_access', ['user_id' => $user['id'], 'library_id' => $this->manifest['library']['id']]);
                if ($grant) {
                    $this->connection->insert('user_library_access', ['user_id' => $user['id'], 'library_id' => $this->manifest['library']['id']]);
                }
                return;
            }
            if ($target === 'playlist') {
                $this->connection->delete('playlist_collaborators', ['user_id' => $user['id'], 'playlist_id' => $this->manifest['playlist']['id']]);
                if ($grant) {
                    $identity = $this->identity();
                    $this->manifest['collaborators'][] = $identity;
                    $this->save();
                    $this->connection->insert('playlist_collaborators', ['id' => $identity['id'], 'user_id' => $user['id'], 'playlist_id' => $this->manifest['playlist']['id'], 'role' => 'editor', 'created_at' => $this->now(), 'updated_at' => $this->now()]);
                }
                return;
            }
            throw new InvalidArgumentException('Unknown authority target.');
        });
        return ['target' => $target, 'userId' => $user['id'], 'granted' => $grant, 'commitStartEpochMs' => $start, 'commitEndEpochMs' => microtime(true) * 1000];
    }

    /**
     * @return array<string, mixed>
     */
    private function projector(bool $hold): array
    {
        if ($hold && !$this->projectorHeld) {
            $this->connection->executeQuery("SELECT pg_advisory_lock(hashtextextended('baander.query-change-projector', 0))")->free();
            $this->projectorHeld = true;
        } elseif (!$hold && $this->projectorHeld) {
            $this->connection->executeQuery("SELECT pg_advisory_unlock(hashtextextended('baander.query-change-projector', 0))")->free();
            $this->projectorHeld = false;
        }
        return ['held' => $this->projectorHeld, 'pid' => getmypid()];
    }

    /**
     * @return array<string, mixed>
     */
    private function sample(): array
    {
        $scopes = [...$this->userIds(), $this->manifest['observer']['id'], $this->manifest['library']['id'], $this->manifest['playlist']['id']];
        return [
            'pid' => getmypid(), 'globalInbox' => $this->connection->fetchAssociative('SELECT count(*)::int AS count, coalesce(extract(epoch FROM (clock_timestamp() - min(created_at))), 0)::float AS "oldestAgeSeconds" FROM query_change_inbox'),
            'scopedInbox' => $this->connection->fetchAssociative('SELECT count(*)::int AS count, coalesce(extract(epoch FROM (clock_timestamp() - min(created_at))), 0)::float AS "oldestAgeSeconds" FROM query_change_inbox WHERE scope_id IN (?)', [$scopes], [ArrayParameterType::STRING]),
            'revisions' => $this->connection->fetchAllAssociative('SELECT family, scope_kind, scope_id, revision FROM query_scope_revision WHERE scope_id IN (?) ORDER BY family, scope_kind, scope_id', [$scopes], [ArrayParameterType::STRING]),
            'catalog' => ['albums' => (int) $this->connection->fetchOne('SELECT count(*) FROM albums WHERE library_id = ?', [$this->manifest['library']['id']]), 'songs' => (int) $this->connection->fetchOne('SELECT count(*) FROM songs s JOIN albums a ON a.id = s.album_id WHERE a.library_id = ?', [$this->manifest['library']['id']])],
            'ownedUsers' => (int) $this->connection->fetchOne('SELECT count(*) FROM users WHERE id IN (?)', [[...$this->userIds(), $this->manifest['observer']['id']]], [ArrayParameterType::STRING]),
            'ownedAccessTokens' => (int) $this->connection->fetchOne('SELECT count(*) FROM oauth_access_tokens WHERE user_id IN (?)', [[...$this->userIds(), $this->manifest['observer']['id']]], [ArrayParameterType::STRING]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanup(int $waitMs): array
    {
        if ($waitMs < 0 || $waitMs > 30_000) {
            throw new InvalidArgumentException('Cleanup waitMs must be between zero and 30000.');
        }
        $deadline = microtime(true) + $waitMs / 1000;
        $this->projector(false);
        if (isset($this->manifest['scan'])) {
            do {
                $scan = $this->scan->status($this->manifest, $this->save(...));
                if ($scan['drained']) {
                    break;
                }
                if (microtime(true) < $deadline) {
                    usleep((int) (min(.25, max(0, $deadline - microtime(true))) * 1_000_000));
                }
            } while (microtime(true) < $deadline);
            if (!$scan['drained']) {
                return ['complete' => false, 'manifestRetained' => true, 'scan' => $scan];
            }
        }
        $ids = [...$this->userIds(), $this->manifest['observer']['id']];
        $this->manifest['accessTokenIds'] = $this->connection->fetchFirstColumn('SELECT id FROM oauth_access_tokens WHERE user_id IN (?)', [$ids], [ArrayParameterType::STRING]);
        $this->manifest['refreshTokenIds'] = $this->connection->fetchFirstColumn('SELECT r.id FROM oauth_refresh_tokens r JOIN oauth_access_tokens a ON a.id = r.access_token_id WHERE a.user_id IN (?)', [$ids], [ArrayParameterType::STRING]);
        $this->save();
        $this->connection->transactional(function () use ($ids): void {
            if (isset($this->manifest['scan'])) {
                $this->scan->deleteTerminalRows($this->manifest);
            }
            $this->connection->delete('job_monitors', ['id' => $this->manifest['job']['id']]);
            $this->connection->delete('playlists', ['id' => $this->manifest['playlist']['id']]);
            $this->connection->delete('libraries', ['id' => $this->manifest['library']['id']]);
            // All user-owned auth tokens and data have explicit cascading user FKs.
            $this->connection->executeStatement('DELETE FROM users WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);
        });
        do {
            $sample = $this->sample();
            if ((int) $sample['scopedInbox']['count'] === 0) {
                break;
            }
            if (microtime(true) < $deadline) {
                usleep((int) (min(.25, max(0, $deadline - microtime(true))) * 1_000_000));
            }
        } while (microtime(true) < $deadline);
        $survivors = (int) $this->connection->fetchOne('SELECT count(*) FROM users WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);
        foreach (['libraries' => 'library', 'playlists' => 'playlist', 'job_monitors' => 'job'] as $table => $key) {
            $survivors += (int) $this->connection->fetchOne("SELECT count(*) FROM $table WHERE id = ?", [$this->manifest[$key]['id']]);
        }
        if ($survivors !== 0 || (int) $sample['scopedInbox']['count'] !== 0) {
            return ['complete' => false, 'survivors' => $survivors, 'sample' => $sample, 'manifestRetained' => true];
        }
        if (isset($this->manifest['scan'])) {
            $this->scan->removeFiles($this->manifest);
        }
        if (is_file($this->manifest['song']['path'])) {
            if (!unlink($this->manifest['song']['path'])) {
                throw new RuntimeException('Cannot remove owned WAV; ownership manifest retained.');
            }
        }
        $mediaDirectory = $this->manifest['library']['path'];
        if ($mediaDirectory !== $this->manifest['directory'] && is_dir($mediaDirectory) && !rmdir($mediaDirectory)) {
            throw new RuntimeException('Owned media leaf is not removable; ownership manifest retained.');
        }
        unlink($this->manifest['directory'] . '/manifest.json');
        rmdir($this->manifest['directory']);
        return ['complete' => true, 'survivors' => 0, 'sample' => $sample, 'manifestRetained' => false];
    }

    /**
     * @param list<array{id: string}> $recipients
     * @return list<array{id: string, count: int, title: string|null}>
     */
    private function notificationState(array $recipients): array
    {
        $result = [];
        foreach ($recipients as $user) {
            $result[] = ['id' => $user['id'], 'count' => (int) $this->connection->fetchOne('SELECT count(*) FROM notifications WHERE user_id = ? AND NOT is_read', [$user['id']]), 'title' => $this->connection->fetchOne('SELECT title FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1', [$user['id']]) ?: null];
        }
        return $result;
    }

    /** @return array{id: string, publicId: string} */
    private function identity(): array
    {
        return ['id' => (string) new Uuid(), 'publicId' => (string) new PublicId()];
    }

    /**
     * @param array{id: string, publicId: string} $identity
     * @param array<string, mixed> $data
     */
    private function insert(string $table, array $identity, array $data): void
    {
        $this->connection->insert($table, ['id' => $identity['id'], 'public_id' => $identity['publicId'], ...$data, 'created_at' => $this->now(), 'updated_at' => $this->now()]);
    }

    /** @param array{id: string, publicId: string} $identity */
    private function insertAlbum(array $identity, int $index): void
    {
        $this->insert('albums', $identity, ['library_id' => $this->manifest['library']['id'], 'title' => sprintf('Live album %05d', $index), 'type' => 'album', 'year' => 1950 + $index % 76]);
    }

    /** @param array{id: string, publicId: string} $identity */
    private function insertSong(array $identity, int $index): void
    {
        $this->insert('songs', $identity, ['album_id' => $this->manifest['album']['id'], 'title' => sprintf('Live song %05d', $index), 'path' => $this->manifest['song']['path'], 'hash' => $this->manifest['song']['hash'], 'size' => filesize($this->manifest['song']['path']), 'mime_type' => 'audio/wav', 'length' => 60, 'sample_rate' => 8000, 'channels' => 1, 'bitrate' => 128_000, 'codec' => 'pcm_s16le', 'track' => $index + 1, 'disc' => 1]);
    }

    /** @return list<string> */
    private function userIds(): array
    {
        return array_column($this->manifest['users'], 'id');
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s.uP');
    }

    private function save(): void
    {
        $path = $this->manifest['directory'] . '/manifest.json';
        $json = json_encode($this->manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (file_put_contents($path . '.tmp', $json) !== strlen($json)
            || !chmod($path . '.tmp', 0600)
            || !rename($path . '.tmp', $path)) {
            throw new RuntimeException('Cannot persist private fixture ownership manifest.');
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    (new Dotenv())->bootEnv(dirname(__DIR__, 3) . '/.env');
    $mediaStorage = $_SERVER['MEDIA_STORAGE_PATH'] ?? $_ENV['MEDIA_STORAGE_PATH'] ?? getenv('MEDIA_STORAGE_PATH');
    if (!is_string($mediaStorage) || !str_starts_with($mediaStorage, '/')) {
        throw new RuntimeException('Fixture requires an absolute configured MEDIA_STORAGE_PATH.');
    }
    $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
    $connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse((string) $url));
    $connection->executeStatement("SET TIME ZONE 'UTC'");
    $redisUrl = $_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? getenv('REDIS_URL');
    $fixture = new LiveCapacityFixture($connection, dirname(__DIR__, 3) . '/var/live-capacity', rtrim($mediaStorage, '/') . '/.live-capacity', new RedisClientFactory((string) $redisUrl));
    $emit = static function (array $value): void {
        fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        fflush(STDOUT);
    };
    try {
        while (($line = fgets(STDIN)) !== false) {
            $command = [];
            try {
                $command = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $base = ['requestId' => $command['requestId'] ?? null, 'op' => $command['op'] ?? null, 'run' => $command['run'] ?? null];
                $receipt = $fixture->execute($command, static function (array $receipt) use ($emit, $base): void {
                    $emit([...$base, 'ok' => true, 'event' => 'batch', 'complete' => false, 'receipt' => $receipt]);
                });
                $emit([...$base, 'run' => $receipt['run'] ?? $base['run'], 'ok' => true, 'complete' => true, 'receipt' => $receipt]);
            } catch (Throwable $error) {
                $emit(['requestId' => $command['requestId'] ?? null, 'op' => $command['op'] ?? null, 'run' => $command['run'] ?? null, 'ok' => false, 'complete' => true, 'error' => $error->getMessage()]);
                // Failure must never leave the controlled projector pause active.
                $fixture->close();
                break;
            }
        }
    } finally {
        $fixture->close();
    }
}
