<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Playlist\Infrastructure\Doctrine\AlbumPlaylistMerge;
use App\Session\Infrastructure\Doctrine\PlaybackStore;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Session\PlaybackPersistenceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

require_once dirname(__DIR__) . '/Fixtures/Session/playback-concurrent-transfer.php';

/**
 * Rows stored before the member-input bounds existed, on disposable PostgreSQL: the repair changes
 * each over-bound value as little as its column allows, reports how many rows each bound repaired,
 * and validates every bound, after which the cascades, merges and session rewrites that failed on
 * those rows succeed.
 */
final class MemberInputBoundRepairMigrationTest extends TestCase
{
    private const string MIGRATION = 'Version20261126212434';

    /** The first migration that bounds member input; the test stores its over-bound rows before it. */
    private const string FIRST_BOUND = 'Version20261125141000';

    private const array BOUNDS = [
        'chk_eq_device_profiles_device_id_length',
        'chk_eq_device_profiles_payload_size',
        'chk_listening_sessions_queue_size',
        'chk_media_activities_last_platform_length',
        'chk_media_activities_last_player_length',
        'chk_playlists_description_length',
        'chk_playlists_name_length',
        'chk_playlists_smart_rules_size',
        'chk_push_subscriptions_auth_key_length',
        'chk_push_subscriptions_endpoint_length',
        'chk_push_subscriptions_public_key_length',
        'chk_push_subscriptions_user_agent_length',
        'chk_radio_sessions_active_stream_url_length',
        'chk_recommendations_name_length',
        'chk_user_sidebar_configs_items_size',
    ];

    private Connection $connection;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'member_bound_repair_' . (random_bytes(8) |> bin2hex(...));
        $this->connection = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->connection->executeStatement("SET TIME ZONE 'UTC'");
        $this->migrateVersions(static fn (string $version): bool => strcmp($version, self::FIRST_BOUND) < 0);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testEachOverBoundValueIsRepairedAsLittleAsItsColumnAllowsAndEveryBoundIsValidated(): void
    {
        $owner = $this->user();
        $controlCharacters = str_repeat("\u{1}", 14_000);
        $overLong = $this->playlist($owner, str_repeat('n', 300), str_repeat('ø', 3000), [['field' => 'genre', 'operator' => 'contains', 'value' => $controlCharacters]]);
        $largestRules = array_fill(0, 50, ['field' => 'date_added', 'operator' => 'is_not_empty', 'value' => str_repeat("\u{1}", 255)]);
        $within = $this->playlist($owner, str_repeat('ø', 255), str_repeat('ø', 2000), $largestRules);
        $activity = $this->activity($owner, str_repeat('p', 100), str_repeat('ø', 64));
        $overProfile = $this->eqProfile($owner, 'Over', str_repeat('i', 300), ['preset' => str_repeat('b', 700_000)]);
        $withinProfile = $this->eqProfile($owner, 'Within', 'speaker', ['gain' => 3]);
        $this->sidebar($owner, 'music', [['id' => 'music-home', 'type' => 'page_link', 'label' => str_repeat('l', 700_000), 'icon' => 'home', 'config' => []]]);
        $this->sidebar($owner, 'video', [['id' => 'video-home', 'type' => 'page_link', 'label' => 'Home', 'icon' => 'home', 'config' => []]]);
        $streamUrl = 'https://radio.baander.app/' . str_repeat('s', 3000);
        $this->radioSession($owner, $streamUrl);
        $queue = array_map(static fn (int $index): string => sprintf('q%020d', $index), range(0, 29_999));
        $this->listeningSession($owner, $queue, 20_000);
        $nearTheEnd = $this->user();
        $this->listeningSession($nearTheEnd, $queue, 40_000);
        $short = $this->user();
        $this->listeningSession($short, ['q00000000000000000000', 'q00000000000000000001'], 1);
        $this->pushSubscription($owner, 'https://push.baander.app/' . str_repeat('e', 3000), 'key', 'auth', 'Firefox');
        $this->pushSubscription($owner, 'https://push.baander.app/long-key', str_repeat('k', 200), 'auth', 'Firefox');
        $agentEndpoint = 'https://push.baander.app/long-agent';
        $this->pushSubscription($owner, $agentEndpoint, 'key', 'auth', str_repeat('ø', 300));
        $this->pushSubscription($owner, 'https://push.baander.app/within', str_repeat('k', 128), str_repeat('a', 64), str_repeat('ø', 255));
        // Cut to 255 characters, these two would collide on the unique index.
        $this->recommendation($owner, str_repeat('r', 255) . str_repeat('a', 45));
        $this->recommendation($owner, str_repeat('r', 255) . str_repeat('b', 45));
        $this->recommendation($owner, str_repeat('ø', 255));
        $this->migrateTheBounds();
        // Stored compressed, the queue was within the former pg_column_size bound; it prints as 750000 bytes.
        self::assertSame(
            [true, 750_000],
            $this->connection->fetchNumeric('SELECT pg_column_size(queue) <= 262144, octet_length(queue::text) FROM listening_sessions WHERE user_id = ?', [$owner]),
        );

        $reported = $this->repair();

        self::assertSame([
            'chk_eq_device_profiles_device_id_length: 1 row over the bound repaired',
            'chk_eq_device_profiles_payload_size: 1 row over the bound repaired',
            'chk_listening_sessions_queue_size: 2 rows over the bound repaired',
            'chk_media_activities_last_platform_length: 1 row over the bound repaired',
            'chk_media_activities_last_player_length: 0 rows over the bound repaired',
            'chk_playlists_description_length: 1 row over the bound repaired',
            'chk_playlists_name_length: 1 row over the bound repaired',
            'chk_playlists_smart_rules_size: 1 row over the bound repaired',
            'chk_push_subscriptions_auth_key_length: 0 rows over the bound repaired',
            'chk_push_subscriptions_endpoint_length: 1 row over the bound repaired',
            'chk_push_subscriptions_public_key_length: 1 row over the bound repaired',
            'chk_push_subscriptions_user_agent_length: 1 row over the bound repaired',
            'chk_radio_sessions_active_stream_url_length: 1 row over the bound repaired',
            'chk_recommendations_name_length: 2 rows over the bound repaired',
            'chk_user_sidebar_configs_items_size: 1 row over the bound repaired',
        ], $reported);

        // Text keeps its first characters; a smart rule set over its bound returns to the column
        // default, and the playlist's version moves so a save that read it before fails.
        self::assertSame([str_repeat('n', 255), str_repeat('ø', 2000), '{}', 1], $this->playlistRow($overLong));
        self::assertSame(
            [str_repeat('ø', 255), str_repeat('ø', 2000), $this->connection->fetchOne('SELECT ?::jsonb::text', [self::json($largestRules)]), 0],
            $this->playlistRow($within),
        );
        self::assertSame([str_repeat('p', 64), str_repeat('ø', 64)], $this->connection->fetchNumeric('SELECT last_platform, last_player FROM media_activities WHERE id = ?', [$activity]));
        self::assertSame([str_repeat('i', 255), '{}'], $this->connection->fetchNumeric('SELECT device_id, payload::text FROM eq_device_profiles WHERE id = ?', [$overProfile]));
        self::assertSame(['speaker', '{"gain": 3}'], $this->connection->fetchNumeric('SELECT device_id, payload::text FROM eq_device_profiles WHERE id = ?', [$withinProfile]));
        self::assertSame(
            ['music' => '[]', 'video' => '[{"id": "video-home", "icon": "home", "type": "page_link", "label": "Home", "config": []}]'],
            $this->connection->fetchAllKeyValue('SELECT media_type, items::text FROM user_sidebar_configs ORDER BY media_type'),
        );
        self::assertSame(substr($streamUrl, 0, 2048), $this->connection->fetchOne('SELECT active_stream_url FROM radio_sessions'));

        // A queue keeps the entries nearest its current one that fit the bound, the current entry
        // stays current, and an index past the end counts as the last entry.
        [$kept, $current] = $this->session($owner);
        self::assertSame(array_slice($queue, 14_758, 10_485), $kept);
        self::assertSame('q00000000000000020000', $kept[$current]);
        [$kept, $current] = $this->session($nearTheEnd);
        self::assertSame(array_slice($queue, 19_515), $kept);
        self::assertSame(10_484, $current);
        self::assertSame([['q00000000000000000000', 'q00000000000000000001'], 1], $this->session($short));
        // A client that read the queue before the repair holds an index into the old queue, so
        // the repair moves the revision its controls are checked against.
        $revision = fn (string $user): int => (int) $this->connection->fetchOne('SELECT revision FROM listening_sessions WHERE user_id = ?', [$user]);
        self::assertSame([1, 1, 0], [$revision($owner), $revision($nearTheEnd), $revision($short)]);
        self::assertSame(
            [262_125, 262_125, 50],
            array_map(intval(...), $this->connection->fetchFirstColumn('SELECT octet_length(queue::text) FROM listening_sessions ORDER BY octet_length(queue::text) DESC')),
        );

        // A subscription with a cut endpoint or key could not deliver; it is deleted. A cut label is kept.
        self::assertSame(
            [$agentEndpoint => str_repeat('ø', 255), 'https://push.baander.app/within' => str_repeat('ø', 255)],
            $this->connection->fetchAllKeyValue('SELECT endpoint, user_agent FROM push_subscriptions ORDER BY endpoint'),
        );
        self::assertSame([str_repeat('ø', 255)], $this->connection->fetchFirstColumn('SELECT name FROM recommendations'));

        self::assertSame(array_fill_keys(self::BOUNDS, true), $this->boundsValidated());
        self::assertSame([
            'chk_eq_device_profiles_payload_size' => 'CHECK ((octet_length((payload)::text) <= 655360))',
            'chk_listening_sessions_queue_size' => 'CHECK ((octet_length((queue)::text) <= 262144))',
            'chk_playlists_smart_rules_size' => 'CHECK ((octet_length((smart_rules)::text) <= 81920))',
            'chk_user_sidebar_configs_items_size' => 'CHECK ((octet_length((items)::text) <= 655360))',
        ], $this->connection->fetchAllKeyValue(
            'SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE connamespace = ?::regnamespace AND conname = ANY (string_to_array(?, \',\')) ORDER BY conname',
            [$this->schema, 'chk_eq_device_profiles_payload_size,chk_listening_sessions_queue_size,chk_playlists_smart_rules_size,chk_user_sidebar_configs_items_size'],
        ));
    }

    public function testTheWritesThatFailedOnAnOverBoundRowSucceedAfterTheRepair(): void
    {
        $owner = $this->user();
        $artist = $this->artist();
        $activity = $this->activity($owner, 'web', str_repeat('p', 100), $artist);
        [$duplicate, $kept] = $this->songs(2);
        $playlist = $this->playlist($owner, 'Merged', str_repeat('d', 3000), []);
        $this->playlistEntry($playlist, $duplicate);
        $this->playlistEntry($playlist, $kept);
        $queue = array_map(static fn (int $index): string => sprintf('q%020d', $index), range(0, 11_999));
        $this->listeningSession($owner, $queue, 11_999);
        $this->migrateTheBounds();
        $manager = PlaybackPersistenceFixture::manager($this->connection);
        $store = new PlaybackStore($manager);
        $merge = new AlbumPlaylistMerge($manager);
        $deleteArtist = fn (): int => $this->connection->executeStatement('DELETE FROM artists WHERE id = ?', [$artist]);
        $foldSongs = static fn () => $merge->foldSongs([[Uuid::fromString($duplicate), Uuid::fromString($kept)]]);
        $rewriteSession = static fn () => $store->saveSession($store->lockSession($owner));

        // A NOT VALID check skips only the scan of stored rows: the cascade's update of the
        // activity, the merge's version bump and the session's rewrite each write a new version of
        // an over-bound row, and each is refused.
        self::assertSame('23514', $this->refusal($deleteArtist), 'artist delete cascading into the activity');
        self::assertSame('23514', $this->refusal($foldSongs), 'album merge over the playlist');
        self::assertSame('23514', $this->refusal($rewriteSession), 'session snapshot rewrite');
        // The former measure read the stored, compressed queue: it validated the row that every
        // rewrite fails on.
        self::assertNull($this->refusal(fn (): int => $this->connection->executeStatement('ALTER TABLE listening_sessions VALIDATE CONSTRAINT chk_listening_sessions_queue_size')));

        $this->repair();

        $this->connection->transactional($deleteArtist);
        self::assertNull($this->connection->fetchOne('SELECT artist_id FROM media_activities WHERE id = ?', [$activity]));
        $this->connection->transactional($foldSongs);
        self::assertSame([$kept], $this->connection->fetchFirstColumn('SELECT song_id FROM playlist_song WHERE playlist_id = ?', [$playlist]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT persistence_version FROM playlists WHERE id = ?', [$playlist]));
        $this->connection->transactional($rewriteSession);
        self::assertSame(
            [10_485, 10_484, 'q00000000000000011999'],
            $this->connection->fetchNumeric('SELECT jsonb_array_length(queue), current_track_index, queue ->> current_track_index FROM listening_sessions WHERE user_id = ?', [$owner]),
        );
    }

    public function testTheRepairReportsNothingWhenItsStatementsDidNotRun(): void
    {
        $this->migrateTheBounds();
        $log = new TestHandler();

        // A dry run calls postUp without executing the statements that create the counts.
        $this->migration(new Logger('migration', [$log]))->postUp(new Schema());

        self::assertSame([], $log->getRecords());
    }

    public function testDowngradingIsRefused(): void
    {
        $this->expectException(IrreversibleMigration::class);
        $this->migration(new NullLogger())->down(new Schema());
    }

    /** Runs the migrations from the first bound up to the repair, as the former release left the schema. */
    private function migrateTheBounds(): void
    {
        $this->migrateVersions(static fn (string $version): bool => strcmp($version, self::FIRST_BOUND) >= 0 && strcmp($version, self::MIGRATION) < 0);
    }

    /** @param callable(string): bool $selected */
    private function migrateVersions(callable $selected): void
    {
        $files = glob(dirname(__DIR__, 2) . '/migrations/Version*.php') ?: [];
        sort($files, SORT_STRING);
        $this->connection->transactional(function () use ($files, $selected): void {
            foreach ($files as $file) {
                $version = basename($file, '.php');
                if (!$selected($version)) {
                    continue;
                }
                require_once $file;
                $class = 'DoctrineMigrations\\' . $version;
                $migration = new $class($this->connection, new NullLogger());
                self::assertInstanceOf(AbstractMigration::class, $migration);
                $migration->up(new Schema());
                $this->execute($migration);
            }
        });
    }

    /**
     * Runs the repair in one transaction and calls postUp before the commit, as Doctrine Migrations does.
     *
     * @return list<string> what the migration reported
     */
    private function repair(): array
    {
        $log = new TestHandler();
        $migration = $this->migration(new Logger('migration', [$log]));
        $migration->up(new Schema());
        $this->connection->transactional(function () use ($migration): void {
            $this->execute($migration);
            $migration->postUp(new Schema());
        });

        return array_map(static fn (LogRecord $record): string => $record->message, $log->getRecords());
    }

    private function migration(LoggerInterface $logger): AbstractMigration
    {
        require_once dirname(__DIR__, 2) . '/migrations/' . self::MIGRATION . '.php';
        $class = 'DoctrineMigrations\\' . self::MIGRATION;
        $migration = new $class($this->connection, $logger);
        self::assertInstanceOf(AbstractMigration::class, $migration);

        return $migration;
    }

    /** Runs the planned statements; extensions are provisioned in the public schema. */
    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            if (preg_match('/\ACREATE EXTENSION\b/i', $query->getStatement() |> ltrim(...)) === 1) {
                continue;
            }
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** The SQLSTATE a write fails with, or null when it succeeds; either way nothing it did is kept. */
    private function refusal(callable $write): ?string
    {
        $this->connection->beginTransaction();
        try {
            $write();

            return null;
        } catch (DriverException $refusal) {
            return $refusal->getSQLState();
        } finally {
            $this->connection->rollBack();
        }
    }

    private function user(): string
    {
        $id = new Uuid()->toString();
        $this->connection->insert('users', [
            'id' => $id,
            'public_id' => new PublicId()->toString(),
            'name' => 'Member',
            'email' => $id . '@baander.app',
            'password' => 'hash',
            'created_at' => '2026-11-20 12:00:00+00',
            'updated_at' => '2026-11-20 12:00:00+00',
        ]);

        return $id;
    }

    /** @param list<array<string, string>> $smartRules */
    private function playlist(string $owner, string $name, string $description, array $smartRules): string
    {
        $id = new Uuid()->toString();
        $this->connection->executeStatement(
            'INSERT INTO playlists (id, public_id, user_id, name, description, smart_rules, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?::jsonb, now(), now())',
            [$id, new PublicId()->toString(), $owner, $name, $description, self::json($smartRules)],
        );

        return $id;
    }

    /** @return array{string, ?string, string, int} name, description, smart rules and version */
    private function playlistRow(string $id): array
    {
        $row = $this->connection->fetchNumeric('SELECT name, description, smart_rules::text, persistence_version FROM playlists WHERE id = ?', [$id]);
        self::assertIsArray($row);
        $row[3] = (int) $row[3];

        return $row;
    }

    private function activity(string $owner, string $platform, string $player, ?string $artist = null): string
    {
        $id = new Uuid()->toString();
        $this->connection->insert('media_activities', [
            'id' => $id,
            'public_id' => new PublicId()->toString(),
            'user_id' => $owner,
            'activity_type' => $artist === null ? 'song' : 'artist',
            'artist_id' => $artist,
            'last_platform' => $platform,
            'last_player' => $player,
            'created_at' => '2026-11-20 12:00:00+00',
            'updated_at' => '2026-11-20 12:00:00+00',
        ]);

        return $id;
    }

    /** @param array<string, mixed> $payload */
    private function eqProfile(string $owner, string $name, string $deviceId, array $payload): string
    {
        $id = new Uuid()->toString();
        $this->connection->executeStatement(
            'INSERT INTO eq_device_profiles (id, public_id, user_id, name, device_id, payload) VALUES (?, ?, ?, ?, ?, ?::jsonb)',
            [$id, new PublicId()->toString(), $owner, $name, $deviceId, self::json($payload)],
        );

        return $id;
    }

    /** @param list<array<string, mixed>> $items */
    private function sidebar(string $owner, string $mediaType, array $items): void
    {
        $this->connection->executeStatement(
            'INSERT INTO user_sidebar_configs (id, user_id, media_type, items, created_at, updated_at) VALUES (?, ?, ?, ?::jsonb, now(), now())',
            [new Uuid()->toString(), $owner, $mediaType, self::json($items)],
        );
    }

    private function radioSession(string $owner, string $streamUrl): void
    {
        $this->connection->insert('radio_sessions', [
            'id' => new Uuid()->toString(),
            'user_id' => $owner,
            'active_stream_url' => $streamUrl,
            'state' => 'playing',
            'created_at' => '2026-11-20 12:00:00+00',
            'updated_at' => '2026-11-20 12:00:00+00',
        ]);
    }

    /** @param list<string> $queue */
    private function listeningSession(string $owner, array $queue, int $currentTrackIndex): void
    {
        $this->connection->executeStatement(
            'INSERT INTO listening_sessions (id, public_id, user_id, queue, current_track_index, created_at, updated_at) VALUES (?, ?, ?, ?::jsonb, ?, now(), now())',
            [new Uuid()->toString(), new PublicId()->toString(), $owner, self::json($queue), $currentTrackIndex],
        );
    }

    /** @return array{list<string>, int} the queue and the current index */
    private function session(string $owner): array
    {
        $row = $this->connection->fetchNumeric('SELECT queue::text, current_track_index FROM listening_sessions WHERE user_id = ?', [$owner]);
        self::assertIsArray($row);

        return [json_decode($row[0], true, flags: JSON_THROW_ON_ERROR), (int) $row[1]];
    }

    private function pushSubscription(string $owner, string $endpoint, string $publicKey, string $authKey, string $userAgent): void
    {
        $this->connection->insert('push_subscriptions', [
            'id' => new Uuid()->toString(),
            'user_id' => $owner,
            'endpoint' => $endpoint,
            'public_key' => $publicKey,
            'auth_key' => $authKey,
            'content_encoding' => 'aes128gcm',
            'user_agent' => $userAgent,
            'created_at' => '2026-11-20 12:00:00+00',
        ]);
    }

    private function recommendation(string $owner, string $name): void
    {
        $this->connection->insert('recommendations', [
            'id' => new Uuid()->toString(),
            'public_id' => new PublicId()->toString(),
            'user_id' => $owner,
            'name' => $name,
            'source_type' => 'song',
            'source_id' => 'source',
            'target_type' => 'song',
            'target_id' => 'target',
            'score' => 0.5,
            'created_at' => '2026-11-20 12:00:00+00',
            'updated_at' => '2026-11-20 12:00:00+00',
        ]);
    }

    private function artist(): string
    {
        $id = new Uuid()->toString();
        $this->connection->insert('artists', [
            'id' => $id,
            'public_id' => new PublicId()->toString(),
            'name' => 'Artist',
            'created_at' => '2026-11-20 12:00:00+00',
            'updated_at' => '2026-11-20 12:00:00+00',
        ]);

        return $id;
    }

    /** @return list<string> the ids of songs on one album */
    private function songs(int $count): array
    {
        $library = new Uuid()->toString();
        $this->connection->insert('libraries', [
            'id' => $library,
            'public_id' => new PublicId()->toString(),
            'name' => 'Library',
            'slug' => 'library-' . $library,
            'path' => '/srv/music/' . $library,
            'type' => 'music',
            'created_at' => '2026-11-20 12:00:00+00',
            'updated_at' => '2026-11-20 12:00:00+00',
        ]);
        $album = new Uuid()->toString();
        $this->connection->insert('albums', [
            'id' => $album,
            'public_id' => new PublicId()->toString(),
            'library_id' => $library,
            'title' => 'Album',
            'type' => 'album',
            'created_at' => '2026-11-20 12:00:00+00',
            'updated_at' => '2026-11-20 12:00:00+00',
        ]);
        $songs = [];
        for ($index = 0; $index < $count; ++$index) {
            $songs[] = $song = new Uuid()->toString();
            $this->connection->insert('songs', [
                'id' => $song,
                'public_id' => new PublicId()->toString(),
                'album_id' => $album,
                'title' => 'Song ' . $index,
                'path' => '/srv/music/' . $library . '/' . $index . '.flac',
                'size' => 1024,
                'mime_type' => 'audio/flac',
                'created_at' => '2026-11-20 12:00:00+00',
                'updated_at' => '2026-11-20 12:00:00+00',
            ]);
        }

        return $songs;
    }

    private function playlistEntry(string $playlist, string $song): void
    {
        $this->connection->insert('playlist_song', [
            'id' => new Uuid()->toString(),
            'playlist_id' => $playlist,
            'song_id' => $song,
            'created_at' => '2026-11-20 12:00:00+00',
            'updated_at' => '2026-11-20 12:00:00+00',
        ]);
    }

    /** @return array<string, bool> constraint name => validated */
    private function boundsValidated(): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            "SELECT conname, convalidated FROM pg_constraint WHERE connamespace = ?::regnamespace AND conname = ANY (string_to_array(?, ',')) ORDER BY conname",
            [$this->schema, implode(',', self::BOUNDS)],
        );

        return array_map(static fn (mixed $validated): bool => (bool) $validated, $rows);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
