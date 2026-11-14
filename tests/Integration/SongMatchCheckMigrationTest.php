<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20261115100000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A song gains the time an album match last compared it. The songs of a matched album that are no
 * newer than its match count as compared from that time; every other song starts unchecked. The
 * mark is bookkeeping for the matcher: neither the migration nor a later change of the mark alone
 * publishes a live update.
 */
final class SongMatchCheckMigrationTest extends TestCase
{
    private const string MIGRATION = 'Version20261115100000';
    private const string RELEASE_ID = '6d1c3e5a-0b2f-4a7e-9c1d-3f5e7a9b1c2d';
    private const string MATCHED_AT = '2026-11-14 12:00:00+00';

    private Connection $connection;
    private string $schema;
    private Uuid $libraryId;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->connection = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
        $this->schema = 'song_match_check_migration_' . (random_bytes(8) |> bin2hex(...));
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

    public function testTheSongsAMatchedAlbumHeldAtItsMatchCountAsComparedThenAndNoOtherSongDoes(): void
    {
        $this->migrateToTheFormerRelease();
        $this->libraryId = $this->insertLibrary();
        $matched = $this->insertAlbum(matched: true);
        $unmatched = $this->insertAlbum(matched: false);
        $compared = $this->insertSong($matched, '2026-11-14 11:59:59.999999+00');
        $atTheMatch = $this->insertSong($matched, self::MATCHED_AT);
        $later = $this->insertSong($matched, '2026-11-14 12:00:00.000001+00');
        $unmatchedSong = $this->insertSong($unmatched, '2026-11-14 11:00:00+00');
        $seeded = $this->lastLiveChange();

        $this->migrate();

        self::assertSame(
            [
                [$compared->toString(), self::MATCHED_AT],
                [$atTheMatch->toString(), self::MATCHED_AT],
                [$later->toString(), null],
                [$unmatchedSong->toString(), null],
            ],
            $this->checkTimes([$compared, $atTheMatch, $later, $unmatchedSong]),
        );
        self::assertSame($seeded, $this->lastLiveChange(), 'Adding and filling the column publishes no live update.');
    }

    public function testTheColumnIsANullableInstant(): void
    {
        $this->migrateToTheFormerRelease();
        $this->migrate();

        self::assertSame(
            [['songs', 'match_checked_at', 'timestamp with time zone', 'YES', null]],
            $this->connection->fetchAllNumeric(
                "SELECT table_name, column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'songs' AND column_name = 'match_checked_at'",
            ),
        );
    }

    public function testMarkingASongAsComparedAlonePublishesNoLiveUpdate(): void
    {
        $this->migrateToTheFormerRelease();
        $this->libraryId = $this->insertLibrary();
        $song = $this->insertSong($this->insertAlbum(matched: true), '2026-11-14 13:00:00+00');
        $this->migrate();
        $before = $this->lastLiveChange();

        $this->connection->executeStatement('UPDATE songs SET match_checked_at = clock_timestamp() WHERE id = ?', [$song->toString()]);

        self::assertSame($before, $this->lastLiveChange());
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

    private function migration(): Version20261115100000
    {
        require_once dirname(__DIR__, 2) . '/migrations/' . self::MIGRATION . '.php';

        return new Version20261115100000($this->connection, new NullLogger());
    }

    /** Runs the planned statements; extensions are provisioned in the public schema, as FinalSchemaBootstrap expects. */
    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            if (preg_match('/\ACREATE EXTENSION\b/i', $query->getStatement() |> ltrim(...)) === 1) {
                continue;
            }
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function insertLibrary(): Uuid
    {
        $id = Uuid::generate();
        $this->connection->executeStatement(
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at) VALUES (?, ?, 'Country', 'country', '/country', 'music', clock_timestamp(), clock_timestamp())",
            [$id->toString(), new PublicId()->toString()],
        );

        return $id;
    }

    private function insertAlbum(bool $matched): Uuid
    {
        $id = Uuid::generate();
        $this->connection->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, release_mbid, matched_at, created_at, updated_at) VALUES (?, ?, ?, 'F-1 Trillion: Long Bed', 'album', ?, ?, clock_timestamp(), clock_timestamp())",
            [$id->toString(), new PublicId()->toString(), $this->libraryId->toString(), $matched ? self::RELEASE_ID : null, $matched ? self::MATCHED_AT : null],
        );

        return $id;
    }

    private function insertSong(Uuid $albumId, string $createdAt): Uuid
    {
        $id = Uuid::generate();
        $this->connection->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, created_at, updated_at) VALUES (?, ?, ?, 'Pour Me A Drink', ?, 1, 'audio/flac', ?, clock_timestamp())",
            [$id->toString(), new PublicId()->toString(), $albumId->toString(), '/country/' . $id->toString() . '.flac', $createdAt],
        );

        return $id;
    }

    /**
     * @param list<Uuid> $songIds
     *
     * @return list<array{string, ?string}> each song's ID and check time, in the order given
     */
    private function checkTimes(array $songIds): array
    {
        return array_map(
            fn (Uuid $songId): array => [
                $songId->toString(),
                $this->connection->fetchOne("SELECT to_char(match_checked_at, 'YYYY-MM-DD HH24:MI:SS') || '+00' FROM songs WHERE id = ?", [$songId->toString()]) ?: null,
            ],
            $songIds,
        );
    }

    private function lastLiveChange(): int
    {
        return (int) $this->connection->fetchOne('SELECT coalesce(max(id), 0) FROM query_change_inbox');
    }
}
