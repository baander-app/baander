<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Command\Movie\UpdateMovieCommand;
use App\Catalog\Application\CommandHandler\Movie\UpdateMovieHandler;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\MovieService;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity as MediaLibraryEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\MovieIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MovieIngestParsingTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private string $schema;
    private Uuid $libraryId;
    private string $root;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a migrated disposable PostgreSQL database.');
        }

        $this->schema = 'movie_parse_' . bin2hex(random_bytes(8));
        $this->root = sys_get_temp_dir() . '/baander-' . $this->schema . '/';
        (new Filesystem())->mkdir($this->root);
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'videos', 'movies', 'movie_video', 'library_file_index', 'video_placements', 'video_placement_episode'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }
        $this->manager = ArtistImportIdentityRace::entityManager($this->connection);
        $library = new MediaLibraryEntity('Movies', 'movie-parse', $this->root, 'movie', 'local');
        $this->manager->persist($library);
        $this->manager->flush();
        $this->libraryId = $library->getId();
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
        if (isset($this->connection)) {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->manager->close();
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testFolderNamesSupplyTheTitleAndYearAndARescanKeepsOneMovie(): void
    {
        $file = $this->file('Heat (1995)/Heat.mp4');
        $this->import([$file]);
        $this->import([$file]);

        $movie = $this->onlyMovie();
        self::assertSame('Heat', $movie->getTitle());
        self::assertSame(1995, $movie->getYear());
        self::assertCount(1, $movie->getVideoIds());
        self::assertSame('Heat (1995)/Heat.mp4', $this->connection->fetchOne('SELECT relative_path FROM video_placements'));
    }

    public function testTrailersAndNestedExtrasBelongToTheFeatureWithoutBecomingVersions(): void
    {
        $trailer = $this->file('Movie (1999)/Movie (1999)-trailer.mp4');
        $nested = $this->file('Movie (1999)/Trailers/Trailer 1.mp4');
        $feature = $this->file('Movie (1999)/Feature.mp4');
        $this->import([$trailer, $nested]);
        $this->import([$feature]);

        $movie = $this->onlyMovie();
        self::assertSame('Movie', $movie->getTitle());
        self::assertSame([$feature->hash], $this->connection->fetchFirstColumn(
            'SELECT video.hash FROM movie_video link JOIN videos video ON video.id = link.video_id',
        ));
        $kinds = $this->connection->fetchFirstColumn('SELECT extra_kind FROM video_placements WHERE extra_kind IS NOT NULL');
        self::assertSame(['trailer', 'trailer'], $kinds);
    }

    public function testRootFilesAreSeparateMoviesEvenWhenTheirParsedTitlesMatch(): void
    {
        $this->import([
            $this->file('Heat (1995).mp4'),
            $this->file('Heat (1995).mkv'),
        ]);

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM movies'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM movie_video'));
    }

    public function testRenamedFolderAndANewFileKeepTheOriginalMovieIdentity(): void
    {
        $original = $this->file('Heat (1995)/Heat.mp4');
        $this->import([$original]);
        $movie = $this->onlyMovie();
        self::assertTrue(unlink($original->absolutePath));
        $renamed = $this->file('Heat (1995) [Remastered]/Heat.mp4', $original->hash);
        $new = $this->file('Heat (1995) [Remastered]/Another version.mp4');

        $this->import([$new, $renamed]);

        self::assertTrue($movie->getId()->equals($this->onlyMovie()->getId()));
        self::assertCount(2, $this->onlyMovie()->getVideoIds());
        self::assertSame($original->absolutePath, $this->connection->fetchOne('SELECT path FROM videos WHERE hash = ?', [$original->hash]));
        self::assertSame($renamed->relativePath, $this->connection->fetchOne(
            'SELECT relative_path FROM video_placements placement JOIN videos video ON video.id = placement.video_id WHERE video.hash = ?',
            [$original->hash],
        ));
    }

    public function testAnAdminTitleRemainsLockedAfterReconstitutionAndAutomaticMetadata(): void
    {
        $this->import([$this->file('Heat (1995)/Heat.mp4')]);
        $movie = $this->onlyMovie();
        $repository = new MovieRepository($this->manager);
        $handler = new UpdateMovieHandler(new MovieService($repository));
        $handler(new UpdateMovieCommand($movie->getPublicId()->toString(), title: 'My Heat'));
        $this->manager->clear();
        $stored = $repository->findByUuid($movie->getId());
        self::assertNotNull($stored);

        $stored->updateMetadata(title: 'Provider Heat', year: 1996);
        $repository->save($stored);
        $this->manager->clear();

        self::assertSame('My Heat', $this->onlyMovie()->getTitle());
        self::assertSame(1996, $this->onlyMovie()->getYear());
        self::assertSame(['title'], $this->onlyMovie()->getLockedFields());
    }

    public function testDuplicatePathsKeepTheFirstAvailablePlacementAndArePrunedWhenRemoved(): void
    {
        $first = $this->file('Heat (1995)/Heat.mp4');
        $this->import([$first]);
        $duplicate = $this->file('Heat (1995)/copy.mp4', $first->hash);

        $this->import([$duplicate, $duplicate]);

        $placement = $this->connection->fetchAssociative('SELECT relative_path, review_reason, raw_parse FROM video_placements');
        self::assertIsArray($placement);
        self::assertSame($first->relativePath, $placement['relative_path']);
        self::assertSame('duplicate_file', $placement['review_reason']);
        self::assertSame([$duplicate->relativePath], json_decode($placement['raw_parse'], true, flags: JSON_THROW_ON_ERROR)['duplicatePaths']);
        self::assertTrue(unlink($duplicate->absolutePath));

        $this->import([$first]);

        self::assertNull($this->connection->fetchOne('SELECT review_reason FROM video_placements'));
        self::assertArrayNotHasKey('duplicatePaths', json_decode(
            $this->connection->fetchOne('SELECT raw_parse FROM video_placements'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    public function testAnAdminExtraAssignmentSurvivesAnUpgradeAtTheSamePath(): void
    {
        $old = $this->file('Heat (1995)/Interview.mp4');
        $this->import([$old]);
        $catalog = new TvCatalogRepository($this->manager);
        $placement = $catalog->placementsByMovie($this->onlyMovie()->getId())[0];
        $placement->assignExtra(ExtraKind::Interview, assigned: true);
        $catalog->savePlacement($placement);
        $catalog->flush();
        $new = $this->file($old->relativePath, hash('sha256', 'upgrade-' . $this->schema));

        $this->import([$new]);

        $state = $this->connection->fetchAssociative(
            'SELECT extra_kind, assigned FROM video_placements placement JOIN videos video ON video.id = placement.video_id WHERE video.hash = ?',
            [$new->hash],
        );
        self::assertSame(['extra_kind' => 'interview', 'assigned' => true], $state);

        $videoId = $this->connection->fetchOne('SELECT id FROM videos WHERE hash = ?', [$new->hash]);
        self::assertIsString($videoId);
        $latest = $catalog->findPlacement($this->libraryId, Uuid::fromString($videoId));
        self::assertNotNull($latest);
        $latest->assignExtra(ExtraKind::Trailer, assigned: true);
        $catalog->savePlacement($latest);
        $catalog->flush();
        $next = $this->file($old->relativePath, hash('sha256', 'second-upgrade-' . $this->schema));

        $this->import([$next]);

        self::assertSame('trailer', $this->connection->fetchOne(
            'SELECT extra_kind FROM video_placements placement JOIN videos video ON video.id = placement.video_id WHERE video.hash = ?',
            [$next->hash],
        ));
    }

    /** @param list<DiscoveredFile> $files */
    private function import(array $files): void
    {
        $this->connection->transactional(function () use ($files): void {
            MovieIdentityRace::import($this->manager, $this->libraryId, $files);
        });
        $this->manager->clear();
    }

    private function file(string $relativePath, ?string $hash = null): DiscoveredFile
    {
        $file = new DiscoveredFile(
            $this->root . $relativePath,
            $relativePath,
            pathinfo($relativePath, PATHINFO_EXTENSION),
            100,
            1,
            $hash ?? hash('sha256', $this->schema . $relativePath),
        );
        (new Filesystem())->mkdir(dirname($file->absolutePath));
        self::assertNotFalse(file_put_contents($file->absolutePath, $file->hash));
        (new LibraryFileIndexRepository($this->manager))->upsert(
            $this->libraryId,
            $file->absolutePath,
            $file->hash,
            $file->size,
            $file->extension,
            $file->modifiedAt,
        );

        return $file;
    }

    private function onlyMovie(): Movie
    {
        $movies = (new MovieRepository($this->manager))->findByLibrary($this->libraryId);
        self::assertCount(1, $movies);

        return $movies[0];
    }
}
