<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Service\MovieLibraryIngest;
use App\Library\Application\Message\FilesDiscovered;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;

/**
 * The developer ingest (app:e2e:ingest-video and scripts/e2e-ingest-video.php)
 * processes discovered files synchronously. It must not also queue them for
 * workers, which would ingest the same files a second time.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MovieLibraryIngestQueueTest extends KernelTestCase
{
    private const string SLUG = 'ingest-queue-test';

    private string $directory;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL and DATABASE_URL to the same fully migrated disposable PostgreSQL database.');
        }

        self::bootKernel();
        $this->directory = sys_get_temp_dir() . '/baander-ingest-queue-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->directory . '/Fixture Movie');
        $ffmpeg = new Process([
            'ffmpeg', '-nostdin', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc=duration=1:size=64x64:rate=5',
            '-pix_fmt', 'yuv420p', $this->directory . '/Fixture Movie/clip.mp4',
        ]);
        $ffmpeg->setTimeout(120);
        $ffmpeg->mustRun();
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            $database = self::getContainer()->get(Connection::class);
            self::assertInstanceOf(Connection::class, $database);
            $libraryId = $database->fetchOne('SELECT id FROM libraries WHERE slug = ?', [self::SLUG]);
            if ($libraryId !== false) {
                $database->executeStatement(
                    'DELETE FROM videos WHERE id IN (SELECT mv.video_id FROM movie_video mv JOIN movies m ON m.id = mv.movie_id WHERE m.library_id = ?)',
                    [$libraryId],
                );
                $database->executeStatement('DELETE FROM library_file_index WHERE library_id = ?', [$libraryId]);
                $database->executeStatement('DELETE FROM libraries WHERE id = ?', [$libraryId]);
            }
            (new Filesystem())->remove($this->directory);
        }
        parent::tearDown();
    }

    public function testIngestProcessesDiscoveredFilesWithoutQueuingThemForWorkers(): void
    {
        $ingest = self::getContainer()->get(MovieLibraryIngest::class);
        self::assertInstanceOf(MovieLibraryIngest::class, $ingest);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);

        $result = $ingest->ingestMovieLibrary('Ingest Queue Test', self::SLUG, $this->directory);

        self::assertCount(1, $result->videoIds);
        $queued = array_filter(
            $async->getSent(),
            static fn ($envelope): bool => $envelope->getMessage() instanceof FilesDiscovered,
        );
        self::assertSame([], $queued, 'Discovered files were processed synchronously and must not also reach the worker queue.');
    }
}
