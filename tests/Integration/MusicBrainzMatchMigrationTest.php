<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20261115090000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An album gains the MusicBrainz release it matched and the time of the match, a song the fields a
 * match set, and a table beside the albums records which of them wait for a match or a review. The
 * album and song changes reach the catalog's live-update audience; the match states reach admins only.
 */
final class MusicBrainzMatchMigrationTest extends TestCase
{
    private const string MIGRATION = 'Version20261115090000';
    private const string RELEASE_ID = '6d1c3e5a-0b2f-4a7e-9c1d-3f5e7a9b1c2d';

    private Connection $connection;
    private string $schema;
    private Uuid $libraryId;
    private Uuid $albumId;
    private Uuid $songId;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->connection = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
        $this->schema = 'musicbrainz_match_migration_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->connection->executeStatement("SET TIME ZONE 'UTC'");
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testExistingAlbumsAndSongsStartUnmatchedWithoutPublishingLiveUpdates(): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedCatalog();
        $seeded = $this->lastLiveChange();

        $this->migrate();

        self::assertSame(
            ['release_mbid' => null, 'matched_at' => null],
            $this->connection->fetchAssociative('SELECT release_mbid, matched_at FROM albums WHERE id = ?', [$this->albumId->toString()]),
        );
        self::assertSame('[]', $this->connection->fetchOne('SELECT matched_fields::text FROM songs WHERE id = ?', [$this->songId->toString()]));
        self::assertSame($seeded, $this->lastLiveChange(), 'Adding the columns publishes no live update.');
    }

    public function testTheNewColumnsAndTableCarryTheirTypesAndNamedConstraints(): void
    {
        $this->migrateToTheFormerRelease();
        $this->migrate();

        self::assertSame([
            ['albums', 'matched_at', 'timestamp with time zone', 'YES', null],
            ['albums', 'release_mbid', 'text', 'YES', null],
            ['songs', 'matched_fields', 'jsonb', 'NO', "'[]'::jsonb"],
        ], $this->columns(['albums', 'songs'], ['release_mbid', 'matched_at', 'matched_fields']));
        self::assertSame([
            ['album_match_states', 'album_id', 'uuid', 'NO', null],
            ['album_match_states', 'best_score', 'smallint', 'YES', null],
            ['album_match_states', 'checked_at', 'timestamp with time zone', 'YES', null],
            ['album_match_states', 'checked_song_count', 'integer', 'YES', null],
            ['album_match_states', 'due_at', 'timestamp with time zone', 'NO', null],
            ['album_match_states', 'reason', 'text', 'YES', null],
            ['album_match_states', 'source', 'text', 'NO', null],
            ['album_match_states', 'state', 'text', 'NO', null],
        ], $this->columns(['album_match_states']));
        self::assertSame([
            ['album_match_states', 'album_match_states_pkey', 'p'],
            ['album_match_states', 'chk_album_match_states_reason', 'c'],
            ['album_match_states', 'chk_album_match_states_source', 'c'],
            ['album_match_states', 'chk_album_match_states_state', 'c'],
            ['album_match_states', 'fk_album_match_states_album_id', 'f'],
            ['albums', 'chk_albums_release_mbid_format', 'c'],
        ], $this->constraints());
        self::assertSame('c', $this->connection->fetchOne(<<<'SQL'
            SELECT c.confdeltype FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
            WHERE n.nspname = current_schema() AND c.conname = 'fk_album_match_states_album_id'
            SQL), 'Deleting an album deletes its match state.');
        $index = (string) $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'idx_album_match_states_state_due_at'");
        self::assertStringStartsWith('CREATE INDEX idx_album_match_states_state_due_at ON ', $index);
        self::assertStringEndsWith('album_match_states USING btree (state, due_at)', $index);
    }

    public function testAnAlbumTakesALowercaseReleaseId(): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedCatalog();
        $this->migrate();

        $this->connection->executeStatement('UPDATE albums SET release_mbid = ? WHERE id = ?', [self::RELEASE_ID, $this->albumId->toString()]);

        self::assertSame(self::RELEASE_ID, $this->connection->fetchOne('SELECT release_mbid FROM albums WHERE id = ?', [$this->albumId->toString()]));
    }

    #[DataProvider('malformedReleaseIds')]
    public function testAMalformedReleaseIdIsRefused(string $releaseId): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedCatalog();
        $this->migrate();

        $this->assertViolates('23514', 'chk_albums_release_mbid_format', fn () => $this->connection->executeStatement(
            'UPDATE albums SET release_mbid = ? WHERE id = ?',
            [$releaseId, $this->albumId->toString()],
        ));
        self::assertNull($this->connection->fetchOne('SELECT release_mbid FROM albums WHERE id = ?', [$this->albumId->toString()]));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedReleaseIds(): iterable
    {
        yield 'uppercase' => [strtoupper(self::RELEASE_ID)];
        yield 'without hyphens' => [str_replace('-', '', self::RELEASE_ID)];
        yield 'with braces' => ['{' . self::RELEASE_ID . '}'];
        yield 'with a trailing space' => [self::RELEASE_ID . ' '];
        yield 'empty' => [''];
        yield 'not an ID' => ['F-1 Trillion: Long Bed'];
    }

    public function testEveryStatusSourceAndReasonTheCatalogKnowsIsStored(): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedCatalog();
        $this->migrate();
        $combinations = [
            ...array_map(static fn (AlbumMatchStatus $status): array => [$status->value, AlbumMatchSource::Admin->value, null], AlbumMatchStatus::cases()),
            ...array_map(static fn (AlbumMatchSource $source): array => [AlbumMatchStatus::Requested->value, $source->value, null], AlbumMatchSource::cases()),
            ...array_map(static fn (AlbumMatchReviewReason $reason): array => [AlbumMatchStatus::NeedsReview->value, AlbumMatchSource::Ingest->value, $reason->value], AlbumMatchReviewReason::cases()),
        ];

        $stored = [];
        foreach ($combinations as $combination) {
            $this->matchState(...$combination);
            $stored[] = $this->connection->fetchNumeric('SELECT state, source, reason FROM album_match_states');
            $this->connection->executeStatement('DELETE FROM album_match_states');
        }

        self::assertSame($combinations, $stored);
    }

    /** @param array{string, string, ?string} $row state, source and reason */
    #[DataProvider('unknownMatchStates')]
    public function testAMatchStateOutsideTheKnownValuesIsRefused(array $row, string $violated): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedCatalog();
        $this->migrate();

        $this->assertViolates('23514', $violated, fn () => $this->matchState(...$row));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM album_match_states'));
    }

    /** @return iterable<string, array{array{string, string, ?string}, string}> */
    public static function unknownMatchStates(): iterable
    {
        yield 'state' => [['matched', 'admin', null], 'chk_album_match_states_state'];
        yield 'source' => [['requested', 'scan', null], 'chk_album_match_states_source'];
        yield 'reason' => [['needs_review', 'ingest', 'unknown'], 'chk_album_match_states_reason'];
    }

    public function testAMatchStateNeedsAnAlbum(): void
    {
        $this->migrateToTheFormerRelease();
        $this->migrate();
        $this->albumId = Uuid::generate();

        $this->assertViolates('23503', 'fk_album_match_states_album_id', fn () => $this->matchState('requested', 'admin', null));
    }

    public function testDeletingAnAlbumRemovesItsMatchState(): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedCatalog();
        $this->migrate();
        $this->matchState('needs_review', 'ingest', 'track_count_differs');

        $this->connection->executeStatement('DELETE FROM albums WHERE id = ?', [$this->albumId->toString()]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM album_match_states'));
    }

    #[DataProvider('matchColumns')]
    public function testChangingAMatchColumnPublishesTheCatalogsLiveUpdate(string $table, string $assignment): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedCatalog();
        $this->migrate();
        $row = $table === 'songs' ? $this->songId : $this->albumId;
        $before = $this->lastLiveChange();

        $this->connection->executeStatement("UPDATE {$table} SET {$assignment} WHERE id = ?", [$row->toString()]);

        self::assertSame([
            ['admin-metadata', 'admin', '*'],
            ['catalog', 'library', $this->libraryId->toString()],
            ['libraries', 'library', $this->libraryId->toString()],
        ], $this->liveChangesAfter($before));
    }

    /** @return iterable<string, array{string, string}> table and the change made to the seeded row */
    public static function matchColumns(): iterable
    {
        yield 'album release ID' => ['albums', "release_mbid = '" . self::RELEASE_ID . "'"];
        yield 'album match time' => ['albums', "matched_at = '2026-11-14 12:30:00+00'"];
        yield 'song matched fields' => ['songs', "matched_fields = '[\"title\", \"credits\"]'"];
    }

    public function testMatchStateChangesReachOnlyAdmins(): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedCatalog();
        $this->migrate();
        $adminOnly = [['admin-metadata', 'admin', '*']];

        $before = $this->lastLiveChange();
        $this->matchState('requested', 'ingest', null);
        self::assertSame($adminOnly, $this->liveChangesAfter($before), 'Requesting a match');

        $before = $this->lastLiveChange();
        $this->connection->executeStatement("UPDATE album_match_states SET state = 'needs_review', reason = 'close_runner_up', checked_song_count = 27, checked_at = clock_timestamp(), best_score = 88");
        self::assertSame($adminOnly, $this->liveChangesAfter($before), 'Marking the album for review');

        $before = $this->lastLiveChange();
        $this->connection->executeStatement('DELETE FROM album_match_states');
        self::assertSame($adminOnly, $this->liveChangesAfter($before), 'Clearing the mark');
    }

    public function testDowngradingIsRefused(): void
    {
        $migration = $this->migration();

        $this->expectException(IrreversibleMigration::class);
        $migration->down(new Schema());
    }

    /** Builds the schema the release before this migration left: every migration before it. */
    private function migrateToTheFormerRelease(): void
    {
        $files = (glob(dirname(__DIR__, 2) . '/migrations/Version*.php') ?: [])
            |> (static fn (array $files): array => array_filter($files, self::precedesTheMigration(...)))
            |> array_values(...);
        sort($files, SORT_STRING);
        self::assertNotSame([], $files);
        $this->connection->transactional(function () use ($files): void {
            foreach ($files as $file) {
                require_once $file;
                $class = 'DoctrineMigrations\\' . basename($file, '.php');
                $migration = new $class($this->connection, new NullLogger());
                self::assertInstanceOf(AbstractMigration::class, $migration);
                $migration->up(new Schema());
                $this->execute($migration);
            }
        });
    }

    private static function precedesTheMigration(string $file): bool
    {
        return strcmp(basename($file, '.php'), self::MIGRATION) < 0;
    }

    /** Runs the migration under test in one transaction, as Doctrine Migrations does. */
    private function migrate(): void
    {
        $migration = $this->migration();
        $migration->up(new Schema());
        $this->connection->transactional(fn () => $this->execute($migration));
    }

    private function migration(): Version20261115090000
    {
        require_once dirname(__DIR__, 2) . '/migrations/' . self::MIGRATION . '.php';

        return new Version20261115090000($this->connection, new NullLogger());
    }

    /** Runs the planned statements; extensions are provisioned in the public schema, as FinalSchemaBootstrap expects. */
    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            if (preg_match('/\ACREATE EXTENSION\b/i', ltrim($query->getStatement())) === 1) {
                continue;
            }
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function seedCatalog(): void
    {
        $this->libraryId = Uuid::generate();
        $this->albumId = Uuid::generate();
        $this->songId = Uuid::generate();
        $this->connection->executeStatement(
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at) VALUES (?, ?, 'Country', 'country', '/country', 'music', clock_timestamp(), clock_timestamp())",
            [$this->libraryId->toString(), new PublicId()->toString()],
        );
        $this->connection->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (?, ?, ?, 'F-1 Trillion: Long Bed', 'album', clock_timestamp(), clock_timestamp())",
            [$this->albumId->toString(), new PublicId()->toString(), $this->libraryId->toString()],
        );
        $this->connection->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, created_at, updated_at) VALUES (?, ?, ?, 'Pour Me A Drink', '/country/pour-me-a-drink.flac', 1, 'audio/flac', clock_timestamp(), clock_timestamp())",
            [$this->songId->toString(), new PublicId()->toString(), $this->albumId->toString()],
        );
    }

    private function matchState(string $state, string $source, ?string $reason): void
    {
        $this->connection->executeStatement(
            'INSERT INTO album_match_states (album_id, state, source, due_at, reason) VALUES (?, ?, ?, clock_timestamp(), ?)',
            [$this->albumId->toString(), $state, $source, $reason],
        );
    }

    /**
     * @param list<string>      $tables
     * @param list<string>|null $columns every column of the tables when null
     *
     * @return list<array{string, string, string, string, ?string}> table, column, type, nullability and default
     */
    private function columns(array $tables, ?array $columns = null): array
    {
        return $this->connection->fetchAllNumeric(
            'SELECT table_name, column_name, data_type, is_nullable, column_default FROM information_schema.columns'
            . ' WHERE table_schema = current_schema() AND table_name = ANY(?::text[])'
            . ($columns === null ? '' : ' AND column_name = ANY(?::text[])')
            . ' ORDER BY table_name COLLATE "C", column_name COLLATE "C"',
            [$this->textArray($tables), ...($columns === null ? [] : [$this->textArray($columns)])],
        );
    }

    /** @return list<array{string, string, string}> table, name and kind of each key and check this migration adds */
    private function constraints(): array
    {
        return $this->connection->fetchAllNumeric(<<<'SQL'
            SELECT t.relname::text, c.conname::text, c.contype::text FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = c.connamespace
            WHERE n.nspname = current_schema() AND c.contype IN ('p', 'c', 'f')
              AND (t.relname = 'album_match_states' OR c.conname = 'chk_albums_release_mbid_format')
            ORDER BY 1 COLLATE "C", 2 COLLATE "C"
            SQL);
    }

    /** @param list<string> $values */
    private function textArray(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }

    private function lastLiveChange(): int
    {
        return (int) $this->connection->fetchOne('SELECT coalesce(max(id), 0) FROM query_change_inbox');
    }

    /** @return list<array{string, string, string}> family, scope kind and scope of each change recorded after $id */
    private function liveChangesAfter(int $id): array
    {
        return $this->connection->fetchAllNumeric(
            'SELECT DISTINCT family, scope_kind, scope_id FROM query_change_inbox WHERE id > ? ORDER BY 1 COLLATE "C", 2 COLLATE "C", 3 COLLATE "C"',
            [$id],
        );
    }

    private function assertViolates(string $state, string $object, \Closure $write): void
    {
        try {
            $write();
        } catch (DriverException $error) {
            self::assertSame($state, $error->getSQLState());
            self::assertStringContainsString($object, $error->getMessage());
            return;
        }
        self::fail('The write satisfied ' . $object . '.');
    }
}
