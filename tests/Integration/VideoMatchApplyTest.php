<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\CommandHandler\VideoMatch\ApplyVideoMatchHandler;
use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Infrastructure\Doctrine\Repository\GenreMovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\GenreRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonCreditRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonIdentityRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\GenreService;
use App\Filesystem\Mime\MimeDetector;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Library\Infrastructure\Filesystem\LibraryMediaFiles;
use App\Library\Infrastructure\Filesystem\MediaFileGuard;
use App\Media\Infrastructure\Doctrine\Repository\ImageRepository;
use App\Media\Domain\Model\Image;
use App\Media\Infrastructure\ImageService;
use App\Media\Infrastructure\ManagedVideoArtworkImporter;
use App\Media\Infrastructure\Storage\FlysystemStorage;
use App\Metadata\Application\DTO\Video\VideoArtwork;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoCredit;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\PersonProfileSyncRequestInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;

final class VideoMatchApplyTest extends TestCase
{
    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    private string $schema;
    private string $root;
    private Connection $connection;
    private EntityManager $manager;
    private LibraryEntity $library;
    private MovieRepository $movies;
    private TvCatalogRepository $catalog;
    private ImageRepository $images;
    private FlysystemStorage $storage;
    private TransactionPortInterface $transaction;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a migrated disposable PostgreSQL database.');
        }
        $this->schema = 'video_metadata_' . bin2hex(random_bytes(8));
        $this->root = sys_get_temp_dir() . '/baander-' . $this->schema;
        (new Filesystem())->mkdir([$this->root . '/library', $this->root . '/storage']);
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        FinalSchemaBootstrap::bootstrap($this->connection);
        $this->manager = ArtistImportIdentityRace::entityManager($this->connection);
        $this->library = new LibraryEntity('Video metadata', 'video-metadata', $this->root . '/library', 'movie', 'local');
        $this->manager->persist($this->library);
        $this->manager->flush();
        $this->movies = new MovieRepository($this->manager);
        $this->catalog = new TvCatalogRepository($this->manager);
        $this->images = new ImageRepository($this->manager);
        $this->storage = new FlysystemStorage($this->root . '/storage');
        $this->transaction = new readonly class ($this->manager) implements TransactionPortInterface {
            public function __construct(private EntityManager $manager) {}

            public function run(callable $operation): mixed
            {
                return $this->manager->getConnection()->transactional(function () use ($operation): mixed {
                    $result = $operation();
                    $this->manager->flush();

                    return $result;
                });
            }
        };
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            $this->manager->close();
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
    }

    public function testLocalArtworkIsManagedAndOnlyIdentifiedLocalActorsAreCredited(): void
    {
        $movie = Movie::create($this->library->getId(), 'Movie');
        $this->movies->save($movie);
        $path = $this->root . '/library/poster.png';
        $bytes = base64_decode(self::PIXEL, true);
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        $metadata = new VideoTitle(new VideoCandidate('local-id', 'Movie'), overview: 'NFO plot', poster: new VideoArtwork($path, $this->library->getId()), cast: [
            new VideoCredit([], 'Name only', 'cast', 'actor', 'Actor'),
            new VideoCredit(['imdb_name' => 'nm0000123'], 'Identified actor', 'cast', 'actor', 'Actor'),
        ]);
        $handler = $this->handler();
        $prepared = $handler->prepare($this->library->getId(), $metadata);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM images'));
        $this->transaction->run(fn () => $handler->apply($movie, VideoProviderId::Local, $prepared));
        $handler->discard($prepared, committed: true);
        $this->manager->clear();
        $stored = $this->movies->findByUuid($movie->getId());
        self::assertNotNull($stored);
        self::assertSame('NFO plot', $stored->getOverview());
        $images = $this->images->findByOwner('video', $this->library->getId());
        self::assertCount(1, $images);
        $image = $images[0];
        self::assertSame('/api/images/' . $image->getPublicId()->toString() . '/file', $stored->getPosterUrl());
        self::assertTrue($this->storage->exists($image->getPath()));
        $prefix = dirname($image->getPath()) . '/';
        $matching = $this->images->findManagedVideoArtwork($this->library->getId(), $prefix);
        self::assertCount(1, $matching);
        self::assertTrue($matching[0]->getId()->equals($image->getId()));
        self::assertSame([], $this->images->findManagedVideoArtwork(Uuid::v7(), $prefix));
        self::assertSame([], $this->images->findManagedVideoArtwork($this->library->getId(), $prefix . 'different/'));
        self::assertSame(['Identified actor'], $this->connection->fetchFirstColumn('SELECT p.name FROM people p JOIN movie_person_credits c ON c.person_id=p.id'));
        $actor = Uuid::v7();
        self::assertNotNull($this->images->findVisibleByPublicId($image->getPublicId(), MediaReadScope::authenticated($actor, LibraryReadScope::restricted([$this->library->getId()]))));
        self::assertNull($this->images->findVisibleByPublicId($image->getPublicId(), MediaReadScope::authenticated($actor, LibraryReadScope::restricted([Uuid::v7()]))));
        $again = $handler->prepare($this->library->getId(), $metadata);
        $this->transaction->run(fn () => $handler->apply($stored, VideoProviderId::Local, $again));
        $handler->discard($again, committed: true);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM images'));
    }

    public function testManagedArtworkLookupUsesLiteralPrefixAndLibraryOwnershipInCreationOrder(): void
    {
        $otherLibrary = new LibraryEntity('Other artwork', 'other-artwork', $this->root . '/other', 'movie', 'local');
        $this->manager->persist($otherLibrary);
        $this->manager->flush();
        $prefix = 'images/video/literal%_\\/';
        $first = Image::create($prefix . 'first.png', 'png', 'image/png', 68, 1, 1, 'video', libraryId: $this->library->getId());
        $second = Image::create($prefix . 'second.png', 'png', 'image/png', 68, 1, 1, 'video', libraryId: $this->library->getId());
        $images = [
            $first,
            $second,
            Image::create('images/video/literal-wildcard/other.png', 'png', 'image/png', 68, 1, 1, 'video', libraryId: $this->library->getId()),
            Image::create($prefix . 'foreign.png', 'png', 'image/png', 68, 1, 1, 'video', libraryId: $otherLibrary->getId()),
            Image::create($prefix . 'artist.png', 'png', 'image/png', 68, 1, 1, 'artist', libraryId: $this->library->getId()),
        ];
        foreach ($images as $image) {
            $this->images->save($image);
        }
        $this->connection->executeStatement("UPDATE images SET created_at = '2026-01-01T00:00:01+00' WHERE id = :id", ['id' => $first->getId()->toString()]);
        $this->connection->executeStatement("UPDATE images SET created_at = '2026-01-01T00:00:02+00' WHERE id = :id", ['id' => $second->getId()->toString()]);
        $found = $this->images->findManagedVideoArtwork($this->library->getId(), $prefix);
        self::assertSame([$first->getId()->toString(), $second->getId()->toString()], array_map(static fn (Image $image): string => $image->getId()->toString(), $found));
    }

    public function testTvProviderOwnershipAndManualLocksSurviveReconstitution(): void
    {
        $series = TvSeries::create($this->library->getId(), 'Folder', 'Folder');
        $this->catalog->saveSeries($series);
        $season = TvSeason::create($series->getId(), 1);
        $this->catalog->saveSeason($season);
        $episode = TvEpisode::create($series->getId(), $season->getId(), 1, 'Episode 1');
        $episode->match('101');
        $this->catalog->saveEpisode($episode);
        $this->catalog->flush();
        $handler = $this->handler();
        $first = new VideoTitle(new VideoCandidate('10', 'Matched series'), overview: 'Initial plot', seasons: [new VideoSeason(1, 'Season one', episodes: [new VideoEpisode('101', 1, 'Pilot', overview: 'Initial episode plot')])]);
        $prepared = $handler->prepare($this->library->getId(), $first);
        $this->transaction->run(fn () => $handler->apply($series, VideoProviderId::Tvdb, $prepared));
        $handler->discard($prepared, committed: true);
        $this->manager->clear();
        $series = $this->catalog->findSeriesByUuid($series->getId());
        $episode = $this->catalog->findEpisodeByUuid($episode->getId());
        self::assertNotNull($series);
        self::assertNotNull($episode);
        self::assertSame('Matched series', $series->getName());
        self::assertSame('Pilot', $episode->getName());
        $series->getState()->overview = 'Editorial plot';
        $series->lockField('overview');
        $this->catalog->saveSeries($series);
        $this->catalog->flush();
        $this->manager->clear();
        $series = $this->catalog->findSeriesByUuid($series->getId());
        self::assertNotNull($series);
        $next = new VideoTitle(new VideoCandidate('10', 'Updated series'), overview: 'Updated provider plot', seasons: [new VideoSeason(1, 'Updated season', episodes: [new VideoEpisode('101', 1, 'Updated pilot', overview: 'Updated episode plot')])]);
        $prepared = $handler->prepare($this->library->getId(), $next);
        $this->transaction->run(fn () => $handler->apply($series, VideoProviderId::Tvdb, $prepared));
        $handler->discard($prepared, committed: true);
        $this->manager->clear();
        $stored = $this->catalog->findSeriesByUuid($series->getId());
        self::assertNotNull($stored);
        self::assertSame('Updated series', $stored->getName());
        self::assertSame('Editorial plot', $stored->getState()->overview);
        self::assertSame(['overview'], $stored->getLockedFields());
        self::assertSame('Updated pilot', $this->catalog->findEpisodeByUuid($episode->getId())?->getName());
    }

    public function testFailureAfterMetadataFlushRollsBackRowsAndDiscardsPreparedFiles(): void
    {
        $movie = Movie::create($this->library->getId(), 'Movie');
        $this->movies->save($movie);
        $path = $this->root . '/library/poster.png';
        $bytes = base64_decode(self::PIXEL, true);
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        $genres = $this->createStub(GenrePortInterface::class);
        $genres->method('findOrCreateForImport')->willReturnCallback(function (): never {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM movie_person_credits'));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM people'));
            throw new RuntimeException('Genre write failed.');
        });
        $profiles = $this->createMock(PersonProfileSyncRequestInterface::class);
        $profiles->expects(self::never())->method('request');
        $handler = $this->handler(genres: $genres, profiles: $profiles);
        $metadata = new VideoTitle(new VideoCandidate('local-id', 'Movie'), overview: 'Uncommitted plot', poster: new VideoArtwork($path, $this->library->getId()), cast: [new VideoCredit(['imdb_name' => 'nm0000123'], 'Uncommitted actor', 'cast', 'actor', 'Actor')], genres: ['Drama']);
        $prepared = $handler->prepare($this->library->getId(), $metadata);
        $storedPath = $prepared->artwork[$path]->image->getPath();
        try {
            $this->transaction->run(fn () => $handler->apply($movie, VideoProviderId::Local, $prepared));
            self::fail('The failing genre write must roll back metadata and credits.');
        } catch (RuntimeException $exception) {
            self::assertSame('Genre write failed.', $exception->getMessage());
            $handler->discard($prepared);
        }
        $this->manager->clear();
        self::assertNull($this->movies->findByUuid($movie->getId())?->getOverview());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM images'));
        self::assertFalse($this->storage->exists($storedPath));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM movie_person_credits'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM people'));
    }

    private function handler(?PersonCreditPortInterface $people = null, ?GenrePortInterface $genres = null, ?PersonProfileSyncRequestInterface $profiles = null): ApplyVideoMatchHandler
    {
        $clock = new NativeClock();
        $files = new LibraryMediaFiles(new LibraryRepository($this->manager, $clock), new LibraryFileIndexRepository($this->manager), new MediaFileGuard(), $this->manager, $clock, new NullLogger());
        $artwork = new ManagedVideoArtworkImporter($files, $this->storage, new ImageService($this->images), new MimeDetector(), new NullLogger());
        $people ??= new PersonCreditRepository($this->connection, new PersonIdentityRepository($this->connection, $this->transaction, $this->manager));

        return new ApplyVideoMatchHandler(
            $this->movies, $this->catalog, $people,
            $genres ?? new GenreService(new GenreRepository($this->manager), new GenreMovieRepository($this->manager)),
            $artwork, $profiles ?? $this->createStub(PersonProfileSyncRequestInterface::class),
        );
    }
}
