<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistIdentityResolver;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistMusicbrainzIdAssigner;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\MusicbrainzIdAssignment;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Imports resolve their artists under advisory locks on every tagged name and MusicBrainz ID.
 * The test session holds a resolution open while a second session, a child process, resolves a
 * competing identity: the second waits on a lock and, once the first commits, reads its artist
 * instead of creating another. A metadata search giving an artist an ID takes the same locks and
 * races an import the same way. The artists table copies the migrated one with uniq_artists_mbid
 * and its B-tree indexes but not its PGroonga index. Advisory locks span the database, so every
 * name and ID is random.
 */
final class ArtistImportIdentityRaceTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private ArtistIdentityResolver $resolver;
    private string $schema;
    private string $tag;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $this->tag = bin2hex(random_bytes(4));
        $this->schema = 'artist_identity_' . bin2hex(random_bytes(8));
        $this->observer = ArtistImportIdentityRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        // The re-read after the locks sees a competing commit only under READ COMMITTED.
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        ArtistImportIdentityRace::copyTableWithoutPgroonga($this->observer, 'artists');

        $this->writer = ArtistImportIdentityRace::connect($this->schema);
        $this->manager = ArtistImportIdentityRace::entityManager($this->writer);
        $this->resolver = ArtistImportIdentityRace::resolver($this->manager);
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

    /** @return iterable<string, array{bool}> */
    public static function whichComesFirst(): iterable
    {
        yield 'the name without an ID first' => [false];
        yield 'the name with the ID first' => [true];
    }

    #[DataProvider('whichComesFirst')]
    public function testANameOnlyImportAndAnImportOfTheNameWithAnIdCreateOneArtistHoldingTheId(bool $idFirst): void
    {
        $name = $this->tagged('H');
        $id = $this->mbid();
        [$first, $second] = $idFirst ? [[$name, [$id]], [$name, []]] : [[$name, []], [$name, [$id]]];

        $this->writer->beginTransaction();
        $held = $this->resolveOne(...$first);
        $child = ArtistImportIdentityRace::start($this->schema, 'resolve', [$second]);
        ArtistImportIdentityRace::awaitAdvisoryLockWait($this->observer, $child);
        $this->writer->commit();

        self::assertSame(['artists' => [$held->toString()]], ArtistImportIdentityRace::finish($child, $this->observer));
        self::assertSame([[$held->toString(), $name, $id]], $this->storedArtists());
    }

    public function testTwoAliasesOfOneIdCreateOneArtistNamedAfterTheFirstCommit(): void
    {
        $id = $this->mbid();

        $this->writer->beginTransaction();
        $held = $this->resolveOne($this->tagged('Headhunterz'), [$id]);
        $child = ArtistImportIdentityRace::start($this->schema, 'resolve', [[$this->tagged('HeadHunterz'), [$id]]]);
        ArtistImportIdentityRace::awaitAdvisoryLockWait($this->observer, $child);
        $this->writer->commit();

        self::assertSame(['artists' => [$held->toString()]], ArtistImportIdentityRace::finish($child, $this->observer));
        self::assertSame([[$held->toString(), $this->tagged('Headhunterz'), $id]], $this->storedArtists());
    }

    public function testTwoNameOnlyAliasesTakingOnOneIdAtOnceLeaveItWithOneArtist(): void
    {
        $id = $this->mbid();
        $first = $this->storeArtist($this->tagged('Headhunterz'), null, '2026-10-01 10:00:00+00');
        $second = $this->storeArtist($this->tagged('HeadHunterz'), null, '2026-10-02 10:00:00+00');

        $this->writer->beginTransaction();
        self::assertSame($first, $this->resolveOne($this->tagged('Headhunterz'), [$id])->toString());
        $child = ArtistImportIdentityRace::start($this->schema, 'resolve', [[$this->tagged('HeadHunterz'), [$id]]]);
        ArtistImportIdentityRace::awaitAdvisoryLockWait($this->observer, $child);
        $this->writer->commit();

        self::assertSame(['artists' => [$first]], ArtistImportIdentityRace::finish($child, $this->observer));
        self::assertSame(
            [[$first, $this->tagged('Headhunterz'), $id], [$second, $this->tagged('HeadHunterz'), null]],
            $this->storedArtists(),
        );
    }

    public function testANameWithoutAnIdResolvesToTheOlderOfTwoSameNameArtistsWithIds(): void
    {
        $name = $this->tagged('Nirvana');
        // Stored newest first, so the answer does not follow the insert order.
        $this->storeArtist($name, $this->mbid(), '2026-11-01 10:00:00+00');
        $older = $this->storeArtist($name, $this->mbid(), '2026-10-01 10:00:00+00');

        $this->writer->beginTransaction();
        self::assertSame($older, $this->resolveOne($name, [])->toString());
        $this->writer->commit();

        self::assertCount(2, $this->storedArtists(), 'No artist is created.');
    }

    public function testANameWithoutAnIdResolvesToTheLowerIdOfTwoNameOnlyArtistsCreatedAtOnce(): void
    {
        $name = $this->tagged('Nirvana');
        $this->storeArtist($name, null, '2026-10-01 10:00:00.123456+00', '0190a000-0000-7000-8000-00000000000a');
        $lower = $this->storeArtist($name, null, '2026-10-01 10:00:00.123456+00', '0190a000-0000-7000-8000-000000000001');

        $this->writer->beginTransaction();
        self::assertSame($lower, $this->resolveOne($name, [])->toString());
        $this->writer->commit();
    }

    public function testFourteenIdenticalIdentitiesInOneBatchCreateOneArtist(): void
    {
        $identities = array_fill(0, 14, new ArtistIdentity($this->tagged('Disturbed')));

        $this->writer->beginTransaction();
        $resolved = $this->resolver->resolve($identities);
        $this->writer->commit();

        self::assertCount(1, $resolved);
        self::assertSame([[array_first($resolved)?->toString(), $this->tagged('Disturbed'), null]], $this->storedArtists());
    }

    #[DataProvider('whichComesFirst')]
    public function testANameWithAndWithoutAnIdInOneBatchStoreOneArtistHoldingTheId(bool $idFirst): void
    {
        $id = $this->mbid();
        $withId = new ArtistIdentity($this->tagged('H'), [$id]);
        $nameOnly = new ArtistIdentity($this->tagged('H'));

        $this->writer->beginTransaction();
        $resolved = $this->resolver->resolve($idFirst ? [$withId, $nameOnly] : [$nameOnly, $withId]);
        $this->writer->commit();

        self::assertSame($resolved[$withId->key()], $resolved[$nameOnly->key()]);
        self::assertSame([[$resolved[$withId->key()]->toString(), $this->tagged('H'), $id]], $this->storedArtists());
    }

    /**
     * The batches of one message share a unit of work. Between them another import gives the
     * artist the first batch created an ID; the second batch must read that ID, not the artist
     * it still holds without one.
     */
    public function testAnIdAnotherImportCommittedBetweenTwoBatchesStaysInPlace(): void
    {
        $name = $this->tagged('H');
        $adopted = $this->mbid();
        $later = $this->mbid();

        $this->writer->beginTransaction();
        $created = $this->resolveOne($name, []);
        $this->writer->commit();

        self::assertSame(['artists' => [$created->toString()]], ArtistImportIdentityRace::finish(
            ArtistImportIdentityRace::start($this->schema, 'resolve', [[$name, [$adopted]]]),
            $this->observer,
        ));
        self::assertNull($this->manager->find(ArtistEntity::class, $created)?->getMbid(), 'The first batch still holds the artist without an ID.');

        $this->writer->beginTransaction();
        $second = $this->resolveOne($name, [$later]);
        $this->writer->commit();

        self::assertNotSame($created->toString(), $second->toString());
        self::assertSame(
            [[$created->toString(), $name, $adopted], [$second->toString(), $name, $later]],
            $this->storedArtists(),
        );
    }

    /**
     * The test session holds the middle key; the child asks for all three out of order. Taken in
     * byte order, the child holds the first key and waits on the middle one, and has not asked for
     * the last.
     */
    public function testLocksAreTakenInByteOrderOfTheirKeys(): void
    {
        [$a, $b, $c] = [ImportIdentityLock::artistNameKey($this->tagged('A')), ImportIdentityLock::artistNameKey($this->tagged('B')), ImportIdentityLock::artistNameKey($this->tagged('C'))];

        $this->writer->beginTransaction();
        ImportIdentityLock::acquire($this->writer, [$b]);
        $child = ArtistImportIdentityRace::start($this->schema, 'lock', [$c, $b, $a, $b]);
        ArtistImportIdentityRace::awaitAdvisoryLockWait($this->observer, $child);
        $locks = $this->advisoryLocksOf($child['application'], [$a, $b, $c]);
        $this->writer->commit();

        self::assertSame([$a => true, $b => false], $locks);
        self::assertSame(['locked' => true], ArtistImportIdentityRace::finish($child, $this->observer));
    }

    /**
     * A metadata search gives an artist an ID under the same name and ID locks. The import holds
     * them first and adopts its ID onto the name-only artist; the search waits on the name lock,
     * then reads the adopted ID and leaves it.
     */
    public function testAnIdAnImportAdoptsStaysWhenAMetadataSearchAssignsAnotherToTheSameArtist(): void
    {
        $name = $this->tagged('H');
        $artist = $this->storeArtist($name, null, '2026-10-01 10:00:00+00');
        $tagged = $this->mbid();

        $this->writer->beginTransaction();
        self::assertSame($artist, $this->resolveOne($name, [$tagged])->toString());
        $child = self::startMusicbrainzIdAssignment($this->schema, [$artist, strtoupper($this->mbid()), $name]);
        ArtistImportIdentityRace::awaitAdvisoryLockWait($this->observer, $child);
        $this->writer->commit();

        self::assertSame(['assignment' => MusicbrainzIdAssignment::ArtistHasId->value], ArtistImportIdentityRace::finish($child, $this->observer));
        self::assertSame([[$artist, $name, $tagged]], $this->storedArtists());
    }

    /**
     * The search holds the locks first and gives the name-only artist its ID; the import waits on
     * the name lock, then finds no same-name artist without an ID and creates one for its own.
     */
    public function testAnImportWaitingOnAMetadataSearchCreatesAnArtistForItsIdOnceTheSearchAssignedAnother(): void
    {
        $name = $this->tagged('H');
        $artist = $this->storeArtist($name, null, '2026-10-01 10:00:00+00');
        $searched = MusicbrainzId::fromString($this->mbid());
        self::assertNotNull($searched);
        $tagged = $this->mbid();

        $this->writer->beginTransaction();
        self::assertSame(
            MusicbrainzIdAssignment::Assigned,
            new ArtistMusicbrainzIdAssigner($this->manager)->assign(Uuid::fromString($artist), $searched, $name),
        );
        $child = ArtistImportIdentityRace::start($this->schema, 'resolve', [[$name, [$tagged]]]);
        ArtistImportIdentityRace::awaitAdvisoryLockWait($this->observer, $child);
        $this->writer->commit();

        $created = ArtistImportIdentityRace::finish($child, $this->observer)['artists'][0] ?? null;
        self::assertIsString($created);
        self::assertNotSame($artist, $created);
        self::assertSame([[$artist, $name, $searched->toString()], [$created, $name, $tagged]], $this->storedArtists());
    }

    public function testResolvingOutsideATransactionIsRefusedBeforeWriting(): void
    {
        try {
            $this->resolver->resolve([new ArtistIdentity($this->tagged('Disturbed'))]);
            self::fail('Resolving outside a transaction must be refused.');
        } catch (\LogicException) {
        }

        self::assertSame([], $this->storedArtists());
    }

    /**
     * Starts the child session that gives an artist a searched MusicBrainz ID in one transaction
     * (artist-musicbrainz-id.php), in the shape ArtistImportIdentityRace waits on and finishes.
     *
     * @param array{0: string, 1: string, 2: string} $assignment artist ID, searched ID and the search hit's name
     *
     * @return array{process: resource, output: resource, application: string}
     */
    private static function startMusicbrainzIdAssignment(string $schema, array $assignment): array
    {
        $application = 'artist_mbid_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Catalog/artist-musicbrainz-id.php', $schema, $application, json_encode($assignment, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @param list<string> $mbids */
    private function resolveOne(string $name, array $mbids): Uuid
    {
        $identity = new ArtistIdentity($name, $mbids);

        return $this->resolver->resolve([$identity])[$identity->key()];
    }

    private function tagged(string $base): string
    {
        return $base . ' ' . $this->tag;
    }

    private function mbid(): string
    {
        return Uuid::v4()->toString();
    }

    private function storeArtist(string $name, ?string $mbid, string $createdAt, ?string $id = null): string
    {
        $id ??= (new Uuid())->toString();
        $this->observer->executeStatement(
            'INSERT INTO artists (id, public_id, name, mbid, created_at, updated_at) VALUES (:id, :publicId, :name, :mbid, :createdAt, :createdAt)',
            ['id' => $id, 'publicId' => (new PublicId())->toString(), 'name' => $name, 'mbid' => $mbid, 'createdAt' => $createdAt],
        );

        return $id;
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> id, name and MusicBrainz ID, oldest first */
    private function storedArtists(): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['id'], (string) $row['name'], $row['mbid'] === null ? null : (string) $row['mbid']],
            $this->observer->fetchAllAssociative('SELECT id::text AS id, name, mbid FROM artists ORDER BY created_at, id'),
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
