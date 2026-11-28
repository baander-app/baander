<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryProvisioningInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MovieIngestCharacterizationTest extends KernelTestCase
{
    private string $root;
    private string $slug;
    private Connection $database;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL and DATABASE_URL to a migrated disposable PostgreSQL database.');
        }

        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->slug = 'movie-ingest-' . bin2hex(random_bytes(8));
        $this->root = sys_get_temp_dir() . '/' . $this->slug;
        (new Filesystem())->mkdir($this->root . '/Fixture Movie');
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $libraryId = $this->database->fetchOne('SELECT id FROM libraries WHERE slug = ?', [$this->slug]);
            if ($libraryId !== false) {
                $this->database->executeStatement('DELETE FROM video_placements WHERE library_id = ?', [$libraryId]);
                $this->database->executeStatement(
                    'DELETE FROM videos WHERE id IN (SELECT mv.video_id FROM movie_video mv JOIN movies m ON m.id = mv.movie_id WHERE m.library_id = ?)',
                    [$libraryId],
                );
                $this->database->executeStatement('DELETE FROM library_file_index WHERE library_id = ?', [$libraryId]);
                $this->database->executeStatement('DELETE FROM libraries WHERE id = ?', [$libraryId]);
            }
            (new Filesystem())->remove($this->root);
        }

        parent::tearDown();
    }

    public function testImportsOneVideoWithItsProbe(): void
    {
        $path = $this->makeVideo();
        $message = $this->scan();

        $this->import($message);

        $video = $this->importedVideo($message);
        self::assertSame($path, $video['path']);
        self::assertSame(64, $video['width']);
        self::assertSame('0', $video['stream_index']);
    }

    public function testARepeatHashAtANewPathReusesTheStoredVideo(): void
    {
        $path = $this->makeVideo();
        $message = $this->scan();
        $this->import($message);
        $video = $this->importedVideo($message);

        rename($path, $this->root . '/Fixture Movie/renamed.mp4');
        $this->import($this->scan());

        self::assertSame($video, $this->importedVideo($message));
    }

    public function testAnIncompleteProbeIsRefreshedAtTheStoredPath(): void
    {
        $this->makeVideo();
        $message = $this->scan();
        $this->import($message);
        $video = $this->importedVideo($message);
        $this->database->executeStatement("UPDATE videos SET probe = '{}' WHERE id = ?", [$video['id']]);
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);

        $this->import($message);

        self::assertSame($video, $this->importedVideo($message));
    }

    public function testARefusedContainerMarksTheFileUnavailable(): void
    {
        $path = $this->makeVideo('gif');
        $message = $this->scan();

        try {
            $this->import($message);
            self::fail('Expected the unsupported container to fail the import.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString($path, $failure->getMessage());
            self::assertStringContainsString('gif', $failure->getMessage());
        }

        $this->assertUnavailable($path);
    }

    public function testAFileDeletedAfterTheScanIsDropped(): void
    {
        $path = $this->makeVideo();
        $message = $this->scan();
        unlink($path);

        $this->import($message);

        $this->assertUnavailable($path);
    }

    public function testADeleteClaimRequeuesAnImportWithoutWritingAVideo(): void
    {
        $this->makeVideo();
        $message = $this->scan();
        $this->database->executeStatement(
            "UPDATE libraries SET claim_kind = 'delete', claim_id = ?, claim_expires_at = now() + interval '15 minutes' WHERE id = ?",
            [Uuid::v7()->toString(), $message->libraryId->toString()],
        );
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $async->reset();

        $this->import($message);

        $queued = $async->getSent();
        self::assertCount(1, $queued);
        self::assertEquals($message, $queued[0]->getMessage());
        self::assertSame(30_000, $queued[0]->last(DelayStamp::class)?->getDelay());
        self::assertSame(0, $this->countVideos($message));
    }

    private function scan(): FilesDiscovered
    {
        $provisioning = self::getContainer()->get(LibraryProvisioningInterface::class);
        $scan = $provisioning->provisionMovieLibrary('Fixture Movie Library', $this->slug, $this->root);
        self::assertCount(1, $scan->discoveries);

        return $scan->discoveries[0];
    }

    private function import(FilesDiscovered $message): void
    {
        $handler = self::getContainer()->get(FilesDiscoveredHandler::class);
        $handler($message);
    }

    private function makeVideo(string $format = 'mp4'): string
    {
        $path = $this->root . '/Fixture Movie/clip.mp4';
        $ffmpeg = new Process([
            'ffmpeg', '-nostdin', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc=duration=1:size=64x64:rate=5',
            '-pix_fmt', 'yuv420p', '-metadata', 'comment=' . $this->slug, '-f', $format, $path,
        ]);
        $ffmpeg->setTimeout(120);
        $ffmpeg->mustRun();

        return $path;
    }

    /** @return array{id: string, path: string, width: int, stream_index: ?string} */
    private function importedVideo(FilesDiscovered $message): array
    {
        self::assertSame(1, $this->countVideos($message));
        $video = $this->database->fetchAssociative(
            "SELECT id, path, width, probe->>'videoStreamIndex' AS stream_index FROM videos WHERE hash = ?",
            [array_first($message->files)?->hash],
        );
        self::assertIsArray($video);

        return $video;
    }

    private function countVideos(FilesDiscovered $message): int
    {
        return (int) $this->database->fetchOne(
            'SELECT count(*) FROM movie_video mv JOIN movies m ON m.id = mv.movie_id WHERE m.library_id = ?',
            [$message->libraryId->toString()],
        );
    }

    private function assertUnavailable(string $path): void
    {
        self::assertSame(1, (int) $this->database->fetchOne(
            'SELECT count(*) FROM library_file_index WHERE path = ? AND NOT available',
            [$path],
        ));
        self::assertSame(0, (int) $this->database->fetchOne('SELECT count(*) FROM videos WHERE path = ?', [$path]));
    }
}
