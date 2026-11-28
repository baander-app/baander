<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\PersonCreditInput;
use App\Catalog\Application\Port\PersonCreditTargetInput;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\PersonWriteLock;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonCreditRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonIdentityRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class TvdbPersonIdentityRaceTest extends TestCase
{
    private Connection $connection;
    private string $schema;
    private TransactionPortInterface $transaction;
    private PersonIdentityRepository $identities;
    private PersonCreditRepository $credits;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to migrated disposable PostgreSQL.');
        }
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->schema = 'tvdb_people_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach ([
            'libraries', 'movies', 'people', 'person_external_ids', 'person_identifier_blocks',
            'person_identity_conflicts', 'movie_person_credits', 'movie_person_credit_state',
            'tv_series', 'tv_seasons', 'tv_episodes', 'series_person_credits', 'series_person_credit_state',
            'episode_person_credits', 'episode_person_credit_state',
        ] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }
        $this->transaction = new class ($this->connection) implements TransactionPortInterface {
            public function __construct(private readonly Connection $connection) {}

            public function run(callable $operation): mixed
            {
                return $this->connection->transactional(static fn () => $operation());
            }
        };
        $this->identities = new PersonIdentityRepository($this->connection, $this->transaction, ArtistImportIdentityRace::entityManager($this->connection));
        $this->credits = new PersonCreditRepository($this->connection, $this->identities);
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

    public function testTvdbAndImdbEvidenceJoinTheExistingPerson(): void
    {
        $movie = $this->movie();
        $owner = $this->transaction->run(fn () => $this->identities->resolve(['imdb_name' => 'nm0000044'], 'Existing Person', 'local'));
        $credit = $this->credit(['tvdb_person' => '440', 'imdb_name' => 'nm0000044', 'tmdb_person' => '44']);

        self::assertTrue($this->transaction->run(fn () => $this->credits->syncMovieCredits($movie, [$credit], null)));
        self::assertSame($owner, $this->identities->owner('tvdb_person', '440'));
        self::assertSame($owner, $this->identities->owner('tmdb_person', '44'));
        self::assertSame($owner, $this->connection->fetchOne('SELECT person_id FROM movie_person_credits'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM people'));
        self::assertSame('tvdb', $this->connection->fetchOne("SELECT source FROM person_external_ids WHERE namespace = 'tvdb_person'"));
    }

    public function testConflictingSharedIdsRecordEvidenceWithoutMergingOrReplacingCredits(): void
    {
        $movie = $this->movie();
        $this->transaction->run(fn () => $this->credits->syncMovieCredits($movie, [$this->credit(['tvdb_person' => '880'])], null));
        $before = $this->connection->fetchAllAssociative('SELECT * FROM movie_person_credits');
        $imdb = $this->transaction->run(fn () => $this->identities->resolve(['imdb_name' => 'nm0000044'], 'First Person', 'local'));
        $tmdb = $this->transaction->run(fn () => $this->identities->resolve(['tmdb_person' => '44'], 'Second Person', 'tmdb'));
        $credit = $this->credit(['tvdb_person' => '440', 'imdb_name' => 'nm0000044', 'tmdb_person' => '44']);

        self::assertFalse($this->transaction->run(fn () => $this->credits->syncMovieCredits($movie, [$credit], null, true)));
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM movie_person_credits'));
        self::assertSame($imdb, $this->identities->owner('imdb_name', 'nm0000044'));
        self::assertSame($tmdb, $this->identities->owner('tmdb_person', '44'));
        self::assertNull($this->identities->owner('tvdb_person', '440'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM person_identity_conflicts'));
        $this->transaction->run(fn () => $this->credits->syncMovieCredits($movie, [$credit], null, true));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM person_identity_conflicts'));
    }

    public function testTvdbOnlyCreditDoesNotMatchAnotherPersonByName(): void
    {
        $movie = $this->movie();
        $imdb = $this->transaction->run(fn () => $this->identities->resolve(['imdb_name' => 'nm0000044'], 'Shared Person', 'local'));
        $this->transaction->run(fn () => $this->credits->syncMovieCredits($movie, [$this->credit(['tvdb_person' => '440'])], null));

        self::assertNotSame($imdb, $this->identities->owner('tvdb_person', '440'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM people'));
    }

    public function testLocalCreditWithoutIdentifiersIsSkipped(): void
    {
        $movie = $this->movie();
        $withId = new PersonCreditInput(['imdb_name' => 'nm0000044'], 'Identified Actor', 'cast', 'actor', 'Actor', source: 'local');
        $withoutId = new PersonCreditInput([], 'Unidentified Actor', 'cast', 'actor', 'Actor', source: 'local');
        $this->transaction->run(fn () => $this->credits->syncMovieCredits($movie, [$withId, $withoutId], null));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM people'));
        self::assertSame(['Identified Actor'], $this->connection->fetchFirstColumn('SELECT name FROM people'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM movie_person_credits'));
    }

    public function testSeriesAndEpisodeCreditsShareIdentityAndReplacePartitionsIndependently(): void
    {
        $manager = ArtistImportIdentityRace::entityManager($this->connection);
        $library = new LibraryEntity('TV', 'tv-person', '/media/tv-person', 'tv_show', 'local');
        $manager->persist($library);
        $manager->flush();
        $catalog = new TvCatalogRepository($manager);
        $series = TvSeries::create($library->getId(), 'Show', 'Show');
        $catalog->saveSeries($series);
        $episode = TvEpisode::create($series->getId(), null, null, 'Absolute episode', 1);
        $catalog->saveEpisode($episode);
        $cast = $this->credit(['tvdb_person' => '440']);
        $crew = new PersonCreditInput(['tvdb_person' => '440'], 'Shared Person', 'crew', 'writer', 'Writer', source: 'tvdb');
        $this->transaction->run(function () use ($series, $episode, $cast, $crew): void {
            $this->credits->syncCredits([
                new PersonCreditTargetInput('series', $series->getId(), [$cast], [$crew]),
                new PersonCreditTargetInput('episode', $episode->getId(), [$cast], [$crew]),
            ]);
        });

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM people'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM series_person_credits'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM episode_person_credits'));
        $this->transaction->run(fn () => $this->credits->syncTvCredits('episode', $episode->getId(), [], null, true));
        self::assertSame(['crew'], $this->connection->fetchFirstColumn('SELECT category FROM episode_person_credits'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM series_person_credits'));
    }

    public function testIdentityAttachmentsAndCreditsShareTheCallersRollback(): void
    {
        $movie = $this->movie();
        $observer = ArtistImportIdentityRace::connect($this->schema);
        try {
            $this->connection->beginTransaction();
            $this->credits->syncMovieCredits($movie, [$this->credit(['tvdb_person' => '440', 'imdb_name' => 'nm0000044'])], null);
            self::assertSame(0, (int) $observer->fetchOne('SELECT count(*) FROM people'));
            self::assertSame(0, (int) $observer->fetchOne('SELECT count(*) FROM person_external_ids'));
            self::assertSame(0, (int) $observer->fetchOne('SELECT count(*) FROM movie_person_credits'));
            $this->connection->rollBack();
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM people'));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM person_external_ids'));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM movie_person_credits'));
        } finally {
            $observer->close();
        }
    }

    public function testProviderCreditIdsAreNamespacedAcrossCastAndCrew(): void
    {
        $movie = $this->movie();
        $cast = $this->credit(['tvdb_person' => '440']);
        $crew = new PersonCreditInput(['tvdb_person' => '440'], 'Shared Person', 'crew', 'director', 'Director', providerCreditId: 'same', source: 'tvdb');
        $this->transaction->run(fn () => $this->credits->syncMovieCredits($movie, [$cast], [$crew]));

        self::assertSame(['cast:tvdb:same', 'crew:tvdb:same'], $this->connection->fetchFirstColumn('SELECT credit_key FROM movie_person_credits ORDER BY credit_key'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM people'));
    }

    public function testConcurrentWorkersLockEverySharedIdInDeterministicOrderAndCreateOnePerson(): void
    {
        $first = $this->movie();
        $second = $this->movie();
        $this->connection->beginTransaction();
        PersonWriteLock::shared($this->connection);
        ImportIdentityLock::acquire($this->connection, [PersonIdentityRepository::identifierLock('imdb_name', 'nm0000044')]);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Catalog/tvdb-person-credit.php', $this->schema, $second->toString()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 10);
        stream_set_timeout($pipes[2], 10);
        try {
            $pid = (int) fgets($pipes[1]);
            self::assertGreaterThan(0, $pid);
            $deadline = microtime(true) + 10;
            do {
                $waiting = $this->connection->fetchOne("SELECT 1 FROM pg_locks WHERE pid = :pid AND locktype = 'advisory' AND NOT granted", ['pid' => $pid]);
                if ($waiting !== false) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertNotFalse($waiting, 'The second worker must wait for the shared identifier.');
            self::assertTrue($this->connection->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:key, 20261114120000))',
                ['key' => PersonIdentityRepository::identifierLock('tvdb_person', '440')],
            ), 'The later identifier must remain unlocked while the worker waits on the first.');
            $this->credits->syncMovieCredits($first, [$this->credit(['imdb_name' => 'nm0000044', 'tvdb_person' => '440'])], null);
            $this->connection->commit();
            self::assertSame("complete\n", fgets($pipes[1]), stream_get_contents($pipes[2]));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM people'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM movie_person_credits'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(DISTINCT person_id) FROM movie_person_credits'));
    }

    public function testBatchWorkLocksSortAcrossTargetsBeforeAnyPartitionIsWritten(): void
    {
        $ids = [$this->movie()->toString(), $this->movie()->toString()];
        sort($ids, SORT_STRING);
        $this->connection->beginTransaction();
        PersonWriteLock::shared($this->connection);
        ImportIdentityLock::acquire($this->connection, ['person-work:movie:' . $ids[0]]);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Catalog/tvdb-person-credit.php', $this->schema, $ids[1], $ids[0]],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 10);
        stream_set_timeout($pipes[2], 10);
        try {
            $pid = (int) fgets($pipes[1]);
            self::assertGreaterThan(0, $pid);
            $deadline = microtime(true) + 10;
            do {
                $waiting = $this->connection->fetchOne("SELECT 1 FROM pg_locks WHERE pid = :pid AND locktype = 'advisory' AND NOT granted", ['pid' => $pid]);
                if ($waiting !== false) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertNotFalse($waiting);
            self::assertTrue($this->connection->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:key, 20261114120000))',
                ['key' => 'person-work:movie:' . $ids[1]],
            ), 'The later work key must remain unlocked until the first key is acquired.');
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM movie_person_credit_state'));
            $this->connection->commit();
            self::assertSame("complete\n", fgets($pipes[1]), stream_get_contents($pipes[2]));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM movie_person_credit_state'));
    }

    /** @param array<string, string> $ids */
    private function credit(array $ids): PersonCreditInput
    {
        return new PersonCreditInput($ids, 'Shared Person', 'cast', 'actor', 'Actor', providerCreditId: 'same', source: 'tvdb');
    }

    private function movie(): Uuid
    {
        $manager = ArtistImportIdentityRace::entityManager($this->connection);
        $library = new LibraryEntity('Movies', 'people-' . bin2hex(random_bytes(4)), '/media/people-' . bin2hex(random_bytes(4)), 'movie', 'local');
        $movie = new MovieEntity(new PublicId(), $library, 'Credited work');
        $manager->persist($library);
        $manager->persist($movie);
        $manager->flush();

        return $movie->getId();
    }
}
