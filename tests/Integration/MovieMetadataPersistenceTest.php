<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Catalog\Infrastructure\Doctrine\Repository\GenreRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\GenreImportRace;
use App\Tests\Fixtures\Metadata\MovieMetadataRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

final class MovieMetadataPersistenceTest extends TestCase
{
    private Connection $observer;
    private Connection $writer;
    private EntityManager $manager;
    private string $schema;
    private Uuid $movieId;
    private Uuid $videoId;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'movie_metadata_' . bin2hex(random_bytes(8));
        $this->observer = ArtistImportIdentityRace::connect('public');
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'movies', 'videos', 'movie_video', 'genres', 'genre_movie', 'people', 'person_external_ids', 'person_identifier_blocks', 'movie_person_credits', 'movie_person_credit_state'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->observer, $table);
        }
        $this->writer = ArtistImportIdentityRace::connect($this->schema);
        $this->manager = ArtistImportIdentityRace::entityManager($this->writer);
        $library = new LibraryEntity('Metadata fixture', 'metadata-fixture', '/media/metadata-fixture', 'movie', 'local');
        $movie = new MovieEntity(new PublicId(), $library, 'Original title');
        $movie->setTmdbId(12);
        $video = new VideoEntity(new PublicId(), '/media/metadata-fixture/original.mkv', hash('xxh3', 'original'));
        foreach ([$library, $movie, $video, new MovieVideoEntity($movie, $video)] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();
        $this->movieId = $movie->getId();
        $this->videoId = $video->getId();
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

    public function testEnrichmentPreservesVideoLinksAndUnrelatedEditsCommittedDuringProviderLookup(): void
    {
        $newVideoId = null;
        $result = MovieMetadataRace::enrich($this->manager, $this->movieId, [], function () use (&$newVideoId): void {
            self::assertFalse($this->writer->isTransactionActive(), 'Provider calls precede the producer transaction.');
            $concurrent = ArtistImportIdentityRace::entityManager($this->observer);
            $movie = $concurrent->find(MovieEntity::class, $this->movieId);
            $movie->setTitle('Concurrent title');
            $movie->setYear(2027);
            $movie->setSummary('Concurrent summary');
            $video = new VideoEntity(new PublicId(), '/media/metadata-fixture/new.mkv', hash('xxh3', 'new'));
            $newVideoId = $video->getId()->toString();
            $concurrent->persist($video);
            $concurrent->persist(new MovieVideoEntity($movie, $video, 1));
            $concurrent->flush();
        });

        self::assertTrue($result->isSuccess());
        self::assertSame([
            'title' => 'Concurrent title',
            'year' => 2027,
            'summary' => 'Concurrent summary',
            'overview' => 'Provider overview',
        ], $this->observer->fetchAssociative('SELECT title, year, summary, overview FROM movies WHERE id = ?', [$this->movieId->toString()]));
        self::assertSame([$this->videoId->toString(), $newVideoId], $this->observer->fetchFirstColumn('SELECT video_id::text FROM movie_video ORDER BY sort_order'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM movie_person_credits'));
    }

    public function testEnrichmentAndConcurrentImportShareOneGenre(): void
    {
        $name = 'Shared ' . bin2hex(random_bytes(6));
        $this->writer->beginTransaction();
        $genreId = new GenreRepository($this->manager)->findOrCreateForImport([$name])[$name];
        $child = MovieMetadataRace::start($this->schema, $this->movieId, [$name]);
        try {
            GenreImportRace::awaitLockWait($this->observer, $child);
        } finally {
            $this->writer->commit();
        }

        self::assertSame(['success' => true], ArtistImportIdentityRace::finish($child, $this->observer));
        self::assertSame([$genreId->toString()], $this->observer->fetchFirstColumn('SELECT id::text FROM genres'));
        self::assertSame([$genreId->toString()], $this->observer->fetchFirstColumn('SELECT genre_id::text FROM genre_movie'));
        self::assertSame('Provider overview', $this->observer->fetchOne('SELECT overview FROM movies'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM movie_person_credits'));
    }

    public function testEnrichmentResolvesAllGenreIdentitiesInSortedOrder(): void
    {
        $tag = bin2hex(random_bytes(6));
        [$a, $b, $c] = ['A ' . $tag, 'B ' . $tag, 'C ' . $tag];
        $this->writer->beginTransaction();
        ImportIdentityLock::acquire($this->writer, ['genre:b-' . $tag]);
        $child = MovieMetadataRace::start($this->schema, $this->movieId, [$c, $b, $a]);
        try {
            GenreImportRace::awaitLockWait($this->observer, $child);
            $held = $this->observer->fetchFirstColumn(
                "SELECT ((l.classid::bigint << 32) | l.objid::bigint)::text
                 FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
                 WHERE a.application_name = ? AND l.locktype = 'advisory' AND l.granted",
                [$child['application']],
            );
            $expected = (string) $this->observer->fetchOne('SELECT hashtextextended(?, 20261114120000)::text', ['genre:a-' . $tag]);
        } finally {
            $this->writer->commit();
        }

        self::assertSame(['success' => true], ArtistImportIdentityRace::finish($child, $this->observer));
        self::assertSame([$expected], $held);
        self::assertSame(3, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM genre_movie'));
    }

    public function testDirectorWriteFailureRollsBackMetadataAndGenres(): void
    {
        $this->observer->executeStatement("ALTER TABLE people ADD CONSTRAINT reject_provider_director CHECK (name <> 'Provider Director')");

        $result = MovieMetadataRace::enrich($this->manager, $this->movieId, ['Rollback ' . bin2hex(random_bytes(6))]);

        self::assertTrue($result->isFailure());
        self::assertNull($this->observer->fetchOne('SELECT overview FROM movies'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM genres'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM genre_movie'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM people'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM movie_person_credits'));
        self::assertSame([$this->videoId->toString()], $this->observer->fetchFirstColumn('SELECT video_id::text FROM movie_video'));
    }
}
