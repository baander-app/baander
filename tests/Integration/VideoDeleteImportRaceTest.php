<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Library\Application\Message\DiscoveredFile;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\MovieIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class VideoDeleteImportRaceTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private string $schema;
    private string $root;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to migrated disposable PostgreSQL.');
        }
        $this->schema = 'video_delete_' . bin2hex(random_bytes(8));
        $this->root = sys_get_temp_dir() . '/baander-' . $this->schema . '/';
        $this->observer = ArtistImportIdentityRace::connect('public');
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'videos', 'movies', 'movie_video', 'library_file_index', 'video_placements', 'video_placement_episode'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->observer, $table);
        }
        foreach ([
            'fk_movie_video_movie_id' => 'movie_video',
            'fk_movie_video_video_id' => 'movie_video',
            'fk_video_placements_movie_id' => 'video_placements',
            'fk_video_placements_video_id' => 'video_placements',
        ] as $constraint => $table) {
            $definition = $this->observer->fetchOne(
                "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = to_regclass(?) AND conname = ?",
                ['public.' . $table, $constraint],
            );
            self::assertIsString($definition);
            $definition = str_replace('REFERENCES public.', 'REFERENCES ', $definition);
            $this->observer->executeStatement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s %s', $table, $constraint, $definition));
        }
        $this->writer = ArtistImportIdentityRace::connect($this->schema);
        $this->manager = ArtistImportIdentityRace::entityManager($this->writer);
    }

    protected function tearDown(): void
    {
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
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function importedPaths(): iterable
    {
        yield 'feature' => ['Heat (1995)/Feature.mp4'];
        yield 'extra' => ['Heat (1995)/Trailers/Trailer.mp4'];
    }

    #[DataProvider('importedPaths')]
    public function testMovieDeleteRollsBackWhenAConcurrentImportCommitsAPlacement(string $relativePath): void
    {
        $source = $this->library('Source', 'source');
        $target = $this->library('Target', 'target');
        $hash = hash('sha256', $this->schema);
        $original = $this->file($source, 'Heat (1995)/Feature.mp4', $hash);
        $this->writer->transactional(function () use ($source, $original): void {
            MovieIdentityRace::import($this->manager, $source->getId(), [$original]);
        });
        $sourcePublicId = $this->writer->fetchOne('SELECT public_id FROM movies WHERE library_id = ?', [$source->getId()->toString()]);
        self::assertIsString($sourcePublicId);
        $copy = $this->file($target, $relativePath, $hash);
        $this->writer->beginTransaction();
        MovieIdentityRace::import($this->manager, $target->getId(), [$copy]);
        $child = $this->startDelete($sourcePublicId);
        try {
            $this->awaitVideoDelete($child);
            self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM movies'));
            $this->writer->commit();
        } finally {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $result = ArtistImportIdentityRace::finish($child, $this->observer);
        }

        self::assertSame('23001', $result['sqlState'] ?? null, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('fk_video_placements_video_id', $result['message']);
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM videos'));
        self::assertSame(2, (int) $this->observer->fetchOne('SELECT count(*) FROM movies'));
        self::assertSame(2, (int) $this->observer->fetchOne('SELECT count(*) FROM video_placements'));
        self::assertSame($copy->relativePath, $this->observer->fetchOne(
            'SELECT relative_path FROM video_placements WHERE library_id = ?',
            [$target->getId()->toString()],
        ));
        self::assertSame(1, (int) $this->observer->fetchOne(
            'SELECT count(*) FROM movies WHERE public_id = ?',
            [$sourcePublicId],
        ));
    }

    private function library(string $name, string $slug): LibraryEntity
    {
        $library = new LibraryEntity($name, $slug, $this->root . $slug, 'movie', 'local');
        (new Filesystem())->mkdir($library->getPath());
        $this->manager->persist($library);
        $this->manager->flush();

        return $library;
    }

    private function file(LibraryEntity $library, string $relativePath, string $hash): DiscoveredFile
    {
        $absolutePath = $library->getPath() . '/' . $relativePath;
        (new Filesystem())->mkdir(dirname($absolutePath));
        self::assertNotFalse(file_put_contents($absolutePath, $hash));
        $file = new DiscoveredFile($absolutePath, $relativePath, 'mp4', strlen($hash), 1, $hash);
        (new LibraryFileIndexRepository($this->manager))->upsert($library->getId(), $absolutePath, $hash, $file->size, 'mp4', 1);

        return $file;
    }

    /** @return array{process: resource, output: resource, application: string} */
    private function startDelete(string $publicId): array
    {
        $application = 'video_delete_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        if ($output === false) {
            throw new RuntimeException('Cannot create video delete contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Catalog/video-delete-import.php', $this->schema, $application, $publicId],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new RuntimeException('Cannot start the video delete contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    private function awaitVideoDelete(array $child): void
    {
        $deadline = microtime(true) + 10;
        do {
            $this->observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            if ($this->observer->fetchOne(
                "SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock' AND wait_event = 'transactionid' AND query LIKE 'DELETE FROM videos%'",
                [$child['application']],
            ) !== false) {
                return;
            }
            if (!proc_get_status($child['process'])['running']) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        rewind($child['output']);
        throw new RuntimeException('Video delete did not wait for the importing transaction: ' . stream_get_contents($child['output']));
    }
}
