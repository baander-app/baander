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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An album match state gains the claim of the queue run that matches the album, as a claim ID and
 * an expiry, and the due time of a request made during the claim. Requests and reviews stored
 * before keep their state without a claim, and only a request can hold one.
 */
final class AlbumMatchClaimMigrationTest extends TestCase
{
    private const string MIGRATION = 'Version20261115120000';
    private const string CLAIM_ID = '0199bf3c-8a00-7000-8000-0a07c0de9e05';

    private Connection $connection;
    private string $schema;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Requires disposable PostgreSQL.');
        }
        $this->connection = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
        $this->schema = 'album_match_claim_migration_' . (random_bytes(8) |> bin2hex(...));
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

    public function testStoredRequestsAndReviewsKeepTheirStateWithoutAClaimAndPublishNoLiveUpdate(): void
    {
        $this->migrateToTheFormerRelease();
        $requested = $this->album('Austin');
        $reviewed = $this->album('F-1 Trillion: Long Bed');
        $this->connection->executeStatement(
            "INSERT INTO album_match_states (album_id, state, source, due_at) VALUES (?, 'requested', 'admin', '2026-11-14 12:00:00.250000+00')",
            [$requested],
        );
        $this->connection->executeStatement(
            "INSERT INTO album_match_states (album_id, state, source, due_at, reason, checked_song_count, checked_at, best_score)"
            . " VALUES (?, 'needs_review', 'ingest', '2026-11-14 11:00:00+00', 'track_count_differs', 26, '2026-11-14 11:05:00.500000+00', 96)",
            [$reviewed],
        );
        $stored = $this->matchStates();
        self::assertCount(2, $stored);
        $published = $this->lastLiveChange();

        $this->migrate();

        self::assertSame(
            array_map(static fn (array $row): array => [...$row, 'claim_id' => null, 'claim_expires_at' => null, 'next_due_at' => null], $stored),
            $this->matchStates(),
        );
        self::assertSame($published, $this->lastLiveChange(), 'Adding the columns publishes no live update.');
    }

    public function testTheClaimColumnsCarryTheirTypesAndTheNamedCheck(): void
    {
        $this->migrateToTheFormerRelease();
        $this->migrate();

        self::assertSame([
            ['claim_expires_at', 'timestamp with time zone', 'YES', null],
            ['claim_id', 'uuid', 'YES', null],
            ['next_due_at', 'timestamp with time zone', 'YES', null],
        ], $this->connection->fetchAllNumeric(<<<'SQL'
            SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'album_match_states'
              AND column_name IN ('claim_id', 'claim_expires_at', 'next_due_at')
            ORDER BY column_name COLLATE "C"
            SQL));
        self::assertSame('c', $this->connection->fetchOne(<<<'SQL'
            SELECT c.contype::text FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
            WHERE n.nspname = current_schema() AND c.conname = 'chk_album_match_states_claim'
            SQL));
    }

    public function testARequestHoldsAClaimWithOrWithoutARequestMadeMeanwhile(): void
    {
        $this->migrateToTheFormerRelease();
        $this->migrate();
        $album = $this->album('Austin');
        $this->connection->executeStatement(
            "INSERT INTO album_match_states (album_id, state, source, due_at) VALUES (?, 'requested', 'ingest', clock_timestamp())",
            [$album],
        );

        $this->connection->executeStatement(
            "UPDATE album_match_states SET claim_id = ?, claim_expires_at = clock_timestamp() + interval '15 minutes'",
            [self::CLAIM_ID],
        );
        $this->connection->executeStatement("UPDATE album_match_states SET next_due_at = clock_timestamp() + interval '5 minutes'");

        self::assertSame(
            ['claim_id' => self::CLAIM_ID, 'claimed' => true, 'waiting' => true],
            $this->connection->fetchAssociative('SELECT claim_id, claim_expires_at IS NOT NULL AS claimed, next_due_at IS NOT NULL AS waiting FROM album_match_states'),
        );
    }

    #[DataProvider('claimsOutsideTheRules')]
    public function testAClaimOutsideItsRulesIsRefused(string $state, string $assignment): void
    {
        $this->migrateToTheFormerRelease();
        $this->migrate();
        $album = $this->album('F-1 Trillion: Long Bed');
        $this->connection->executeStatement(
            'INSERT INTO album_match_states (album_id, state, source, due_at, reason, checked_at)'
            . " VALUES (?, ?, 'ingest', clock_timestamp(), CASE WHEN ? = 'needs_review' THEN 'low_score' END, CASE WHEN ? = 'needs_review' THEN clock_timestamp() END)",
            [$album, $state, $state, $state],
        );
        $before = $this->matchStates();

        try {
            $this->connection->executeStatement('UPDATE album_match_states SET ' . $assignment);
            self::fail('The write satisfied chk_album_match_states_claim.');
        } catch (DriverException $error) {
            self::assertSame('23514', $error->getSQLState());
            self::assertStringContainsString('chk_album_match_states_claim', $error->getMessage());
        }
        self::assertSame($before, $this->matchStates());
    }

    /** @return iterable<string, array{string, string}> the row's state and the change made to it */
    public static function claimsOutsideTheRules(): iterable
    {
        $claim = "claim_id = '" . self::CLAIM_ID . "'";
        $expiry = "claim_expires_at = clock_timestamp() + interval '15 minutes'";

        yield 'a claim on a review' => ['needs_review', $claim . ', ' . $expiry];
        yield 'a claim without an expiry' => ['requested', $claim];
        yield 'an expiry without a claim' => ['requested', $expiry];
        yield 'a request made meanwhile without a claim' => ['requested', 'next_due_at = clock_timestamp()'];
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
                $this->execute($this->load($file));
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
        $migration = $this->load(dirname(__DIR__, 2) . '/migrations/' . self::MIGRATION . '.php');
        $this->connection->transactional(fn () => $this->execute($migration));
    }

    private function load(string $file): AbstractMigration
    {
        require_once $file;
        $class = 'DoctrineMigrations\\' . basename($file, '.php');
        $migration = new $class($this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);
        $migration->up(new Schema());

        return $migration;
    }

    /** Runs the planned statements; extensions are provisioned in the public schema, as FinalSchemaBootstrap expects. */
    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $sql = $query->getStatement();
            if (preg_match('/\ACREATE EXTENSION\b/i', ltrim($sql)) === 1) {
                continue;
            }
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return string the ID of a new album in a library of its own */
    private function album(string $title): string
    {
        $library = Uuid::generate()->toString();
        $album = Uuid::generate()->toString();
        $name = 'country-' . (random_bytes(4) |> bin2hex(...));
        $this->connection->executeStatement(
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at) VALUES (?, ?, 'Country', ?, ?, 'music', clock_timestamp(), clock_timestamp())",
            [$library, new PublicId()->toString(), $name, '/' . $name],
        );
        $this->connection->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (?, ?, ?, ?, 'album', clock_timestamp(), clock_timestamp())",
            [$album, new PublicId()->toString(), $library, $title],
        );

        return $album;
    }

    /** @return list<array<string, mixed>> every match state with all its columns */
    private function matchStates(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM album_match_states ORDER BY album_id');
    }

    private function lastLiveChange(): int
    {
        return (int) $this->connection->fetchOne('SELECT coalesce(max(id), 0) FROM query_change_inbox');
    }
}
