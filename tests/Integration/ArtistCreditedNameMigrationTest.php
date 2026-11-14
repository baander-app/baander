<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20261114120000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Song and album credits gain the name they were credited under, and an artist MusicBrainz ID
 * belongs to one artist. Credits that exist before the upgrade take their artist's name, and the
 * live-update capture treats the credited name like the credit's other columns.
 */
final class ArtistCreditedNameMigrationTest extends TestCase
{
    private const string MIGRATION = 'Version20261114120000';
    private const string MBID = '3d5c3a1e-7f1b-4f3c-9a59-5b3b5e1d2c10';

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
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $this->schema = 'credited_name_migration_' . bin2hex(random_bytes(8));
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

    public function testExistingCreditsTakeTheirArtistsNameWithoutPublishingLiveUpdates(): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedCatalog();
        $headliner = $this->artist('Headhunterz');
        $guest = $this->artist('Sound Rush');
        $this->formerCredit('artist_song', 'song_id', $this->songId, $headliner, 'primary');
        $this->formerCredit('artist_song', 'song_id', $this->songId, $guest, 'featured');
        $this->formerCredit('artist_album', 'album_id', $this->albumId, $headliner, 'primary');
        $seeded = $this->lastLiveChange();

        $this->migrate();

        self::assertSame([['featured', 'Sound Rush'], ['primary', 'Headhunterz']], $this->creditedNames('artist_song'));
        self::assertSame([['primary', 'Headhunterz']], $this->creditedNames('artist_album'));
        self::assertSame($seeded, $this->lastLiveChange(), 'Filling in the credited names publishes no live update.');
        self::assertSame(['NO', 'NO'], $this->connection->fetchFirstColumn(<<<'SQL'
SELECT is_nullable FROM information_schema.columns
WHERE table_schema = current_schema() AND column_name = 'credited_name' AND table_name IN ('artist_album', 'artist_song')
ORDER BY table_name COLLATE "C"
SQL));
    }

    /** @param string $violated the violated constraint, %s standing for the table */
    #[DataProvider('missingNames')]
    public function testACreditWithoutACreditedNameIsRefused(?string $name, string $state, string $violated): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedCatalog();
        $this->migrate();
        $artist = $this->artist('Headhunterz');

        foreach (['artist_song' => ['song_id', $this->songId], 'artist_album' => ['album_id', $this->albumId]] as $table => [$column, $target]) {
            $this->assertViolates($state, sprintf($violated, $table), fn () => $this->credit($table, $column, $target, $artist, 'primary', $name));
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT (SELECT count(*) FROM artist_song) + (SELECT count(*) FROM artist_album)'));
    }

    /** @return iterable<string, array{string|null, string, string}> */
    public static function missingNames(): iterable
    {
        yield 'empty' => ['', '23514', 'chk_%s_credited_name_not_blank'];
        yield 'spaces only' => ['   ', '23514', 'chk_%s_credited_name_not_blank'];
        yield 'none' => [null, '23502', 'column "credited_name" of relation "%s"'];
    }

    public function testTwoArtistsCannotHoldTheSameMusicbrainzId(): void
    {
        $this->migrateToTheFormerRelease();
        $this->migrate();
        $this->artist('Headhunterz', self::MBID);

        $this->assertViolates('23505', 'uniq_artists_mbid', fn () => $this->artist('HeadHunterz', self::MBID));

        self::assertSame(['Headhunterz'], $this->connection->fetchFirstColumn('SELECT name FROM artists WHERE mbid = ?', [self::MBID]));
    }

    public function testArtistsWithoutAMusicbrainzIdCanShareTheirName(): void
    {
        $this->migrateToTheFormerRelease();
        $this->migrate();

        $first = $this->artist('Headhunterz');
        $second = $this->artist('Headhunterz');

        self::assertEqualsCanonicalizing(
            [$first->toString(), $second->toString()],
            $this->connection->fetchFirstColumn("SELECT id::text FROM artists WHERE name = 'Headhunterz' AND mbid IS NULL"),
        );
    }

    #[DataProvider('creditTables')]
    public function testChangingOnlyTheCreditedNamePublishesALiveUpdate(string $table, string $column): void
    {
        $this->migrateToTheFormerRelease();
        $this->seedCatalog();
        $this->migrate();
        $target = $column === 'song_id' ? $this->songId : $this->albumId;
        $credit = $this->credit($table, $column, $target, $this->artist('Headhunterz'), 'primary', 'Headhunterz');
        $before = $this->lastLiveChange();

        $this->connection->executeStatement("UPDATE {$table} SET credited_name = 'HeadHunterz' WHERE id = ?", [$credit->toString()]);

        self::assertSame([
            ['admin-metadata', 'admin', '*'],
            ['catalog', 'library', $this->libraryId->toString()],
            ['libraries', 'library', $this->libraryId->toString()],
        ], $this->liveChangesAfter($before));
    }

    /** @return iterable<string, array{string, string}> */
    public static function creditTables(): iterable
    {
        yield 'song credit' => ['artist_song', 'song_id'];
        yield 'album credit' => ['artist_album', 'album_id'];
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

    private function migration(): Version20261114120000
    {
        require_once dirname(__DIR__, 2) . '/migrations/' . self::MIGRATION . '.php';

        return new Version20261114120000($this->connection, new NullLogger());
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
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at) VALUES (?, ?, 'Credited names', 'credited-names', '/credited-names', 'music', clock_timestamp(), clock_timestamp())",
            [$this->libraryId->toString(), (new PublicId())->toString()],
        );
        $this->connection->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (?, ?, ?, 'Rescue Me', 'album', clock_timestamp(), clock_timestamp())",
            [$this->albumId->toString(), (new PublicId())->toString(), $this->libraryId->toString()],
        );
        $this->connection->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, created_at, updated_at) VALUES (?, ?, ?, 'Rescue Me', '/credited-names/rescue-me.flac', 1, 'audio/flac', clock_timestamp(), clock_timestamp())",
            [$this->songId->toString(), (new PublicId())->toString(), $this->albumId->toString()],
        );
    }

    private function artist(string $name, ?string $mbid = null): Uuid
    {
        $id = Uuid::generate();
        $this->connection->executeStatement(
            'INSERT INTO artists (id, public_id, name, mbid, created_at, updated_at) VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$id->toString(), (new PublicId())->toString(), $name, $mbid],
        );

        return $id;
    }

    /** A credit as the release before this migration stored it, without a credited name. */
    private function formerCredit(string $table, string $column, Uuid $target, Uuid $artist, string $role): void
    {
        $this->connection->executeStatement(
            "INSERT INTO {$table} (id, artist_id, {$column}, role) VALUES (?, ?, ?, ?)",
            [Uuid::generate()->toString(), $artist->toString(), $target->toString(), $role],
        );
    }

    private function credit(string $table, string $column, Uuid $target, Uuid $artist, string $role, ?string $creditedName): Uuid
    {
        $id = Uuid::generate();
        $this->connection->executeStatement(
            "INSERT INTO {$table} (id, artist_id, {$column}, role, credited_name) VALUES (?, ?, ?, ?, ?)",
            [$id->toString(), $artist->toString(), $target->toString(), $role, $creditedName],
        );

        return $id;
    }

    /** @return list<array{string, string}> role and credited name of every credit in the table */
    private function creditedNames(string $table): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['role'], (string) $row['credited_name']],
            $this->connection->fetchAllAssociative("SELECT role, credited_name FROM {$table} ORDER BY role COLLATE \"C\""),
        );
    }

    private function lastLiveChange(): int
    {
        return (int) $this->connection->fetchOne('SELECT coalesce(max(id), 0) FROM query_change_inbox');
    }

    /** @return list<array{string, string, string}> family, scope kind and scope of each change recorded after $id */
    private function liveChangesAfter(int $id): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['family'], (string) $row['scope_kind'], (string) $row['scope_id']],
            $this->connection->fetchAllAssociative(
                'SELECT family, scope_kind, scope_id FROM query_change_inbox WHERE id > ? ORDER BY family COLLATE "C", scope_kind COLLATE "C", scope_id COLLATE "C"',
                [$id],
            ),
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
