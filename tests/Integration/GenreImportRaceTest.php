<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\GenreImportRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Imports create their genres under an advisory lock on each genre's slug. The test session holds
 * an import's transaction open while a second session, a child process, imports a genre with the
 * same slug: the second waits and, once the first commits, reads its genre instead of inserting a
 * duplicate that uniq_genres_slug would refuse, failing that import's flush and closing its entity
 * manager. The genres table copies the migrated one with uniq_genres_slug, uniq_genres_name_lower
 * and its B-tree indexes but not its PGroonga index. Advisory locks span the database, so every
 * genre name is random.
 */
final class GenreImportRaceTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private string $schema;
    private string $tag;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $this->tag = bin2hex(random_bytes(4));
        $this->schema = 'genre_import_' . bin2hex(random_bytes(8));
        $this->observer = ArtistImportIdentityRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        // The lookup after the locks sees a competing commit only under READ COMMITTED.
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        ArtistImportIdentityRace::copyTableWithoutPgroonga($this->observer, 'genres');

        $this->writer = ArtistImportIdentityRace::connect($this->schema);
        $this->manager = ArtistImportIdentityRace::entityManager($this->writer);
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $this->writer->close();
        }
        if (isset($this->observer)) {
            $this->observer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->observer->close();
        }
    }

    public function testTwoImportsCreatingOneGenreAtOnceBothCommitWithOneGenre(): void
    {
        $rock = $this->tagged('Rock');

        $this->writer->beginTransaction();
        $held = GenreImportRace::import($this->manager, [$rock])[$rock];
        $child = GenreImportRace::start($this->schema, [$rock]);
        GenreImportRace::awaitLockWait($this->observer, $child);
        $this->writer->commit();

        self::assertSame(['genres' => [$held]], ArtistImportIdentityRace::finish($child, $this->observer));
        self::assertSame([[$held, $rock, 'rock-' . $this->tag]], $this->storedGenres());
    }

    public function testDifferentlyCasedNamesInOneBatchResolveToOneGenre(): void
    {
        [$lower, $upper] = [$this->tagged('rock'), $this->tagged('Rock')];

        $this->writer->beginTransaction();
        $genres = GenreImportRace::import($this->manager, [$lower, $upper]);
        $this->writer->commit();

        self::assertSame($genres[$lower], $genres[$upper]);
        self::assertSame([[$genres[$lower], $this->tagged('Rock'), 'rock-' . $this->tag]], $this->storedGenres());
    }

    public function testTwoImportsOfNamesSharingASlugBothCommitWithOneGenre(): void
    {
        [$hyphenated, $spaced] = [$this->tagged('Hip-Hop'), $this->tagged('Hip Hop')];

        $this->writer->beginTransaction();
        $held = GenreImportRace::import($this->manager, [$hyphenated])[$hyphenated];
        $child = GenreImportRace::start($this->schema, [$spaced]);
        GenreImportRace::awaitLockWait($this->observer, $child);
        $this->writer->commit();

        self::assertSame(['genres' => [$held]], ArtistImportIdentityRace::finish($child, $this->observer));
        self::assertSame([[$held, $hyphenated, 'hip-hop-' . $this->tag]], $this->storedGenres());
    }

    /**
     * The test session holds the middle genre's key and the child imports all three in reverse
     * order. Taken in byte order, the child holds the first key, waits on the middle one and has
     * not asked for the last, so the test session's own import of the middle and last genres goes
     * ahead. Locked in the order of the tags, the child would hold the last key while waiting on
     * the middle one, and the two imports would deadlock.
     */
    public function testAnImportWithGenresInReverseOrderToAConcurrentImportCommitsWithoutDeadlock(): void
    {
        [$a, $b, $c] = [$this->tagged('A'), $this->tagged('B'), $this->tagged('C')];
        [$keyA, $keyB, $keyC] = ['genre:a-' . $this->tag, 'genre:b-' . $this->tag, 'genre:c-' . $this->tag];

        $this->writer->beginTransaction();
        ImportIdentityLock::acquire($this->writer, [$keyB]);
        $child = GenreImportRace::start($this->schema, [$c, $b, $a]);
        GenreImportRace::awaitLockWait($this->observer, $child);
        $locks = $this->advisoryLocksOf($child['application'], [$keyA, $keyB, $keyC]);
        $held = GenreImportRace::import($this->manager, [$b, $c]);
        $this->writer->commit();

        self::assertSame([$keyA => true, $keyB => false], $locks);
        $imported = ArtistImportIdentityRace::finish($child, $this->observer)['genres'] ?? null;
        self::assertIsArray($imported);
        self::assertSame([$held[$c], $held[$b]], array_slice($imported, 0, 2), 'The child reads the genres the test session committed.');
        self::assertSame(
            [[$imported[2], $a, 'a-' . $this->tag], [$held[$b], $b, 'b-' . $this->tag], [$held[$c], $c, 'c-' . $this->tag]],
            $this->storedGenres(),
        );
    }

    private function tagged(string $base): string
    {
        return $base . ' ' . $this->tag;
    }

    /** @return list<array{0: string, 1: string, 2: string}> id, name and slug, by slug */
    private function storedGenres(): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['id'], (string) $row['name'], (string) $row['slug']],
            $this->observer->fetchAllAssociative('SELECT id::text AS id, name, slug FROM genres ORDER BY slug'),
        );
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, bool> whether each key's lock is granted to the session, for the keys it holds or waits on
     */
    private function advisoryLocksOf(string $application, array $keys): array
    {
        $locks = [];
        foreach ($keys as $key) {
            // A bigint advisory key shows its high half in classid and its low half in objid.
            // Rows, not fetchOne(): a waiting lock's granted is false, which fetchOne() also
            // returns when no row matches.
            $rows = $this->observer->fetchAllAssociative(
                "SELECT l.granted FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
                 WHERE a.application_name = :application AND l.locktype = 'advisory' AND l.objsubid = 1
                   AND l.database = (SELECT oid FROM pg_database WHERE datname = current_database())
                   AND ((l.classid::bigint << 32) | l.objid::bigint) = hashtextextended(:key, 20261114120000)",
                ['application' => $application, 'key' => $key],
            );
            if ($rows !== []) {
                $locks[$key] = (bool) $rows[0]['granted'];
            }
        }

        return $locks;
    }
}
