<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Command\Person\ManagePersonCommand;
use App\Catalog\Application\Port\PersonCreditInput;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\PersonWriteLock;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonAdministrationRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonArtistLinkRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonCreditRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonIdentityRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PersonIdentityPersistenceTest extends TestCase
{
    private Connection $connection;
    private string $schema;
    private TransactionPortInterface $transaction;
    private PersonIdentityRepository $identities;
    private PersonCreditRepository $credits;
    private PersonArtistLinkRepository $artistLinks;
    private PersonAdministrationRepository $administration;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->schema = 'person_identity_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach ([
            'libraries', 'movies', 'people', 'artists', 'person_external_ids',
            'movie_person_credits', 'movie_person_credit_state', 'person_identifier_blocks',
            'person_artist_links', 'person_id_aliases', 'person_identity_conflicts',
            'series_person_credits', 'episode_person_credits',
        ] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }

        $this->transaction = new class ($this->connection) implements TransactionPortInterface {
            public function __construct(private readonly Connection $connection) {}

            public function run(callable $operation): mixed
            {
                return $this->connection->transactional(static fn() => $operation());
            }
        };
        $this->identities = new PersonIdentityRepository($this->connection, $this->transaction, ArtistImportIdentityRace::entityManager($this->connection));
        $this->credits = new PersonCreditRepository($this->connection, $this->identities);
        $this->artistLinks = new PersonArtistLinkRepository($this->connection, $this->identities);
        $this->administration = new PersonAdministrationRepository(
            $this->connection,
            $this->transaction,
            $this->identities,
            $this->artistLinks,
            new NullLogger(),
            ArtistImportIdentityRace::entityManager($this->connection),
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->connection->close();
    }

    public function testCastAndCrewReplaceIndependentlyWithoutDuplicatingAProfile(): void
    {
        $movie = $this->movie();
        $cast = [
            new PersonCreditInput(['tmdb_person' => '22'], 'Alex Fields', 'cast', 'actor', 'Actor', 'Pilot', providerCreditId: 'cast-1'),
            new PersonCreditInput(['tmdb_person' => '22'], 'Alex Fields', 'cast', 'actor', 'Actor', 'Navigator', providerCreditId: 'cast-2'),
        ];
        $crew = [new PersonCreditInput(['tmdb_person' => '22'], 'Alex Fields', 'crew', 'director', 'Director', providerCreditId: 'crew-1')];
        self::assertTrue($this->transaction->run(fn() => $this->credits->syncMovieCredits($movie, $cast, $crew)));
        $credits = $this->connection->fetchFirstColumn('SELECT id FROM movie_person_credits ORDER BY credit_key');
        self::assertCount(3, $credits);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM people'));

        $this->transaction->run(fn() => $this->credits->syncMovieCredits($movie, $cast, $crew, true));
        self::assertSame($credits, $this->connection->fetchFirstColumn('SELECT id FROM movie_person_credits ORDER BY credit_key'));
        self::assertTrue($this->transaction->run(fn() => $this->credits->syncMovieCredits($movie, [], null, true)));
        self::assertSame(['director'], $this->connection->fetchFirstColumn('SELECT role FROM movie_person_credits'));
        self::assertFalse($this->transaction->run(fn() => $this->credits->syncMovieCredits($movie, $cast, $crew)));
    }

    public function testProviderEvidenceAndOperatorCorrectionConvergeOnOneIdentity(): void
    {
        $movie = $this->movie();
        $screenCredit = new PersonCreditInput(['tmdb_person' => '22'], 'Alex Fields', 'crew', 'director', 'Director');
        $this->transaction->run(fn() => $this->credits->syncMovieCredits($movie, null, [$screenCredit]));
        $mbid = Uuid::v7()->toString();
        $matched = $this->transaction->run(fn() => $this->identities->resolve([
            'tmdb_person' => '22',
            'musicbrainz_artist' => $mbid,
            'apple_music_artist' => '81',
            'imdb_name' => 'nm0000042',
        ], 'Artist Name', 'musicbrainz'));
        self::assertSame($matched, $this->identities->owner('tmdb_person', '22'));
        self::assertSame($matched, $this->identities->owner('apple_music_artist', '81'));
        $publicId = $this->connection->fetchOne('SELECT public_id FROM people WHERE id = ?', [$matched]);
        $this->administration->execute(new ManagePersonCommand('edit', $publicId, null, null, ['gender' => null]));
        $this->identities->enrichTmdbPerson('22', [], ['gender' => 'male', 'birth_date' => '1980-04']);
        self::assertSame(
            ['gender' => null, 'birth_date' => '1980-04-01', 'birth_precision' => 2],
            $this->connection->fetchAssociative('SELECT gender, birth_date, birth_precision FROM people WHERE id = ?', [$matched]),
        );
        $this->administration->execute(new ManagePersonCommand('release-field', $publicId, 'gender', null, []));
        $this->identities->enrichTmdbPerson('22', [], ['gender' => 'male']);
        self::assertSame('male', $this->connection->fetchOne('SELECT gender FROM people WHERE id = ?', [$matched]));
    }

    public function testLateIdentificationRecordsAConflictAndMergeRetainsBothPublicIds(): void
    {
        $movie = $this->movie();
        $director = new PersonCreditInput(['tmdb_person' => '22'], 'Alex Fields', 'crew', 'director', 'Director');
        $writer = new PersonCreditInput(['tmdb_person' => '23'], 'Alex Fields', 'crew', 'writer', 'Writer');
        $this->transaction->run(fn() => $this->credits->syncMovieCredits($movie, null, [$director, $writer]));
        $source = $this->identities->owner('tmdb_person', '22');
        $survivor = $this->identities->owner('tmdb_person', '23');
        self::assertNotSame($source, $survivor);

        $this->identities->enrichTmdbPerson('23', ['imdb_name' => 'nm0000042'], []);
        $this->identities->enrichTmdbPerson('22', ['imdb_name' => 'nm0000042'], []);
        $conflicts = $this->administration->execute(new ManagePersonCommand('conflicts', null, null, null, []));
        self::assertCount(1, $conflicts['conflicts']);

        $sourceId = $this->connection->fetchOne('SELECT public_id FROM people WHERE id = ?', [$source]);
        $survivorId = $this->connection->fetchOne('SELECT public_id FROM people WHERE id = ?', [$survivor]);
        $command = new ManagePersonCommand('merge', $sourceId, $survivorId, null, []);
        self::assertSame(['publicId' => $survivorId], $this->administration->execute($command));
        self::assertSame($survivorId, $this->administration->execute($command)['publicId']);
        self::assertSame($survivor, $this->connection->fetchOne('SELECT person_id FROM person_id_aliases WHERE public_id = ?', [$sourceId]));
        self::assertSame($survivor, $this->identities->owner('tmdb_person', '22'));
        self::assertSame(['director', 'writer'], $this->connection->fetchFirstColumn('SELECT role FROM movie_person_credits ORDER BY role'));
        self::assertSame(['conflicts' => []], $this->administration->execute(new ManagePersonCommand('conflicts', null, null, null, [])));
    }

    public function testCuratedLivingStatusRejectsInconsistentEditAndMerge(): void
    {
        $living = $this->administration->execute(new ManagePersonCommand('create', null, null, null, [
            'name' => 'Shared name', 'is_deceased' => false,
        ]))['publicId'];
        $deceased = $this->administration->execute(new ManagePersonCommand('create', null, null, null, [
            'name' => 'Shared name',
        ]))['publicId'];
        $deceasedId = $this->connection->fetchOne('SELECT id FROM people WHERE public_id = ?', [$deceased]);
        $this->transaction->run(fn() => $this->identities->fillProfile($deceasedId, ['death_date' => '2020-01-01']));
        foreach ([[$living, $deceased], [$deceased, $living]] as [$source, $target]) {
            try {
                $this->administration->execute(new ManagePersonCommand('merge', $source, $target, null, []));
                self::fail('The conflicting living status must be resolved.');
            } catch (ConflictException $error) {
                self::assertStringContainsString('death date', $error->getMessage());
            }
        }
        try {
            $this->administration->execute(new ManagePersonCommand('edit', $deceased, null, null, ['is_deceased' => false]));
            self::fail('An edit cannot leave a death date on a living profile.');
        } catch (InvalidInputException $error) {
            self::assertStringContainsString('death date', $error->getMessage());
        }
        $livingId = $this->connection->fetchOne('SELECT id FROM people WHERE public_id = ?', [$living]);
        $this->transaction->run(fn() => $this->identities->fillProfile($livingId, ['death_date' => '2020-01-01']));
        self::assertSame(['death_date' => null, 'is_deceased' => false], $this->connection->fetchAssociative(
            'SELECT death_date, is_deceased FROM people WHERE id = ?',
            [$livingId],
        ));
    }

    public function testUnresolvedConflictFollowsAnOwnerMergedIntoAThirdProfile(): void
    {
        $first = $this->transaction->run(fn() => $this->identities->resolve(['tmdb_person' => '101'], 'First', 'tmdb'));
        $other = $this->transaction->run(fn() => $this->identities->resolve(['imdb_name' => 'nm0000042'], 'Other', 'musicbrainz'));
        $third = $this->administration->execute(new ManagePersonCommand('create', null, null, null, ['name' => 'Survivor']))['publicId'];
        $firstPublic = $this->connection->fetchOne('SELECT public_id FROM people WHERE id = ?', [$first]);
        $otherPublic = $this->connection->fetchOne('SELECT public_id FROM people WHERE id = ?', [$other]);
        $this->identities->enrichTmdbPerson('101', ['imdb_name' => 'nm0000042'], []);
        $this->administration->execute(new ManagePersonCommand('merge', $firstPublic, $third, null, []));
        $conflicts = $this->administration->execute(new ManagePersonCommand('conflicts', null, null, null, []))['conflicts'];
        self::assertCount(1, $conflicts);
        self::assertEqualsCanonicalizing([$third, $otherPublic], $conflicts[0]['people']);
    }

    public function testEnrichmentWaitingOnAMergeResolvesTheSurvivor(): void
    {
        $movie = $this->movie();
        $this->transaction->run(fn () => $this->credits->syncMovieCredits($movie, null, [
            new PersonCreditInput(['tmdb_person' => '22'], 'Imported Person', 'crew', 'director', 'Director'),
        ]));
        $source = $this->identities->owner('tmdb_person', '22');
        $sourcePublicId = $this->connection->fetchOne('SELECT public_id FROM people WHERE id = ?', [$source]);
        $targetPublicId = $this->administration->execute(new ManagePersonCommand(
            'create',
            null,
            null,
            null,
            ['name' => 'Confirmed Person'],
        ))['publicId'];

        $this->connection->beginTransaction();
        PersonWriteLock::exclusive($this->connection);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Catalog/person-profile-import.php', $this->schema, '22', 'nm0000042'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 10);
        try {
            $pid = (int) fgets($pipes[1]);
            self::assertGreaterThan(0, $pid);
            $deadline = microtime(true) + 10;
            do {
                $waiting = $this->connection->fetchOne(
                    "SELECT 1 FROM pg_locks WHERE pid = :pid AND locktype = 'advisory' AND NOT granted LIMIT 1",
                    ['pid' => $pid],
                );
                if ($waiting !== false) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertNotFalse($waiting, 'The importer must wait for the merge transaction.');
            $this->administration->execute(new ManagePersonCommand('merge', $sourcePublicId, $targetPublicId, null, []));
            $this->connection->commit();
            self::assertSame("complete\n", fgets($pipes[1]));
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
        self::assertSame($this->identities->owner('tmdb_person', '22'), $this->identities->owner('imdb_name', 'nm0000042'));
        self::assertSame('Profile from a concurrent request', $this->connection->fetchOne('SELECT biography FROM people WHERE public_id = ?', [$targetPublicId]));
    }

    private function movie(): Uuid
    {
        $manager = ArtistImportIdentityRace::entityManager($this->connection);
        $library = new LibraryEntity('Movies', 'people-test', '/media/people-test', 'movie', 'local');
        $movie = new MovieEntity(new PublicId(), $library, 'Credited work');
        $manager->persist($library);
        $manager->persist($movie);
        $manager->flush();

        return $movie->getId();
    }
}
