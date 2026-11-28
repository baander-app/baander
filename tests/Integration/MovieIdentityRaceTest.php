<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Library\Application\Message\DiscoveredFile;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\MovieIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MovieIdentityRaceTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private string $schema;
    private Uuid $libraryId;
    private string $root;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a migrated disposable PostgreSQL database.');
        }
        $this->schema = 'movie_identity_' . bin2hex(random_bytes(8));
        $this->root = sys_get_temp_dir() . '/baander-' . $this->schema . '/';
        (new Filesystem())->mkdir($this->root);
        $this->observer = ArtistImportIdentityRace::connect('public');
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'videos', 'movies', 'movie_video', 'library_file_index', 'video_placements', 'video_placement_episode'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->observer, $table);
        }
        $this->writer = ArtistImportIdentityRace::connect($this->schema);
        $this->manager = ArtistImportIdentityRace::entityManager($this->writer);
        $library = new LibraryEntity('Movies', 'movie-race', $this->root, 'movie', 'local');
        $this->manager->persist($library);
        $this->manager->flush();
        $this->libraryId = $library->getId();
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $this->manager->close();
            $this->writer->close();
        }
        if (isset($this->observer)) {
            $this->observer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->observer->close();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function firstImport(): iterable
    {
        yield 'feature first' => [false];
        yield 'trailer first' => [true];
    }

    #[DataProvider('firstImport')]
    public function testParallelFeatureAndTrailerImportsCreateOneMovie(bool $trailerFirst): void
    {
        $feature = $this->file('Movie (1999)/Feature.mp4');
        $trailer = $this->file('Movie (1999)/Trailers/Trailer.mp4');
        [$first, $second] = $trailerFirst ? [$trailer, $feature] : [$feature, $trailer];
        $this->writer->beginTransaction();
        MovieIdentityRace::import($this->manager, $this->libraryId, [$first]);
        $id = $this->writer->fetchOne('SELECT id FROM movies');
        $child = MovieIdentityRace::start($this->schema, $this->libraryId, $second);
        try {
            MovieIdentityRace::awaitLockWait($this->observer, $child);
            self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM movies'));
            $this->writer->commit();
        } finally {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $secondId = MovieIdentityRace::finish($child);
        }

        self::assertSame($id, $secondId);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM movies'));
        self::assertSame(2, (int) $this->observer->fetchOne('SELECT count(*) FROM video_placements'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM movie_video'));
        self::assertSame($feature->hash, $this->observer->fetchOne(
            'SELECT video.hash FROM movie_video link JOIN videos video ON video.id = link.video_id',
        ));
    }

    public function testAContenderWaitsForTheSharedVideoHashAcrossDifferentFolders(): void
    {
        $first = $this->file('First (1999)/Feature.mp4');
        $copy = $this->file('Second (1999)/Feature.mp4', $first->hash);
        $this->writer->beginTransaction();
        MovieIdentityRace::import($this->manager, $this->libraryId, [$first]);
        $id = $this->writer->fetchOne('SELECT id FROM movies');
        $child = MovieIdentityRace::start($this->schema, $this->libraryId, $copy);
        try {
            MovieIdentityRace::awaitLockWait($this->observer, $child);
            $this->writer->commit();
        } finally {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            MovieIdentityRace::finish($child);
        }

        self::assertSame([$id], $this->observer->fetchFirstColumn('SELECT id FROM movies'));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM videos'));
        self::assertSame($first->relativePath, $this->observer->fetchOne('SELECT relative_path FROM video_placements'));
        self::assertSame('duplicate_file', $this->observer->fetchOne('SELECT review_reason FROM video_placements'));
    }

    private function file(string $relativePath, ?string $hash = null): DiscoveredFile
    {
        $file = new DiscoveredFile(
            $this->root . $relativePath,
            $relativePath,
            'mp4',
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
}
