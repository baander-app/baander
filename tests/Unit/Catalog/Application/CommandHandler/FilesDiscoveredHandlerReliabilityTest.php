<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\Model\DiscoveredFile;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Confirms that FilesDiscoveredHandler collects per-file failures and reports
 * them in a single exception instead of aborting the batch on the first error
 * or silently swallowing them.
 */
#[AllowMockObjectsWithoutExpectations]
final class FilesDiscoveredHandlerReliabilityTest extends TestCase
{
    public function testFileProcessingFailureIsCollectedAndReported(): void
    {
        $libraryId = Uuid::v4();
        $directory = '/music/Test Album';

        $file = new DiscoveredFile(
            absolutePath: '/music/Test Album/01-track.mp3',
            relativePath: 'Test Album/01-track.mp3',
            extension: 'mp3',
            size: 1_000_000,
            modifiedAt: time(),
            hash: 'deadbeef',
        );

        $album = Album::create($libraryId, 'Test Album', 'Studio');
        $metadata = (new ExtractedMetadata())
            ->setTitle('Track One')
            ->setDuration(180.0)
            ->setArtist('Test Artist');

        $albumPort = $this->createMock(AlbumPortInterface::class);
        $albumPort->method('findByTitleAndLibrary')->willReturn($album);

        $songPort = $this->createMock(SongPortInterface::class);
        $songPort->method('findByHash')->willReturn(null);
        $songPort->method('persist')->willThrowException(new RuntimeException('Database write failed'));

        $genrePort = $this->createMock(GenrePortInterface::class);
        $moviePort = $this->createMock(MoviePortInterface::class);
        $videoRepository = $this->createMock(VideoRepositoryInterface::class);

        $metadataReader = $this->createMock(MetadataContentReaderPortInterface::class);
        $metadataReader->method('readMetadata')->willReturn($metadata);

        $ffprobe = new FFprobeAdapter(new JsonEncoder());
        $messageBus = $this->createMock(MessageBusInterface::class);
        $logger = new NullLogger();

        $handler = new FilesDiscoveredHandler(
            $albumPort,
            $genrePort,
            $songPort,
            $moviePort,
            $videoRepository,
            $metadataReader,
            $ffprobe,
            $messageBus,
            $logger,
        );

        $message = new FilesDiscovered($libraryId, 'music', $directory, [$file]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to process 1 file(s)');
        $this->expectExceptionMessage('/music/Test Album/01-track.mp3');
        $this->expectExceptionMessage('Database write failed');

        $handler($message);
    }

    public function testMultipleFileFailuresAreReportedTogether(): void
    {
        $libraryId = Uuid::v4();
        $directory = '/music/Test Album';

        $fileOne = new DiscoveredFile(
            absolutePath: '/music/Test Album/01-track.mp3',
            relativePath: 'Test Album/01-track.mp3',
            extension: 'mp3',
            size: 1_000_000,
            modifiedAt: time(),
            hash: 'deadbeef',
        );

        $fileTwo = new DiscoveredFile(
            absolutePath: '/music/Test Album/02-track.mp3',
            relativePath: 'Test Album/02-track.mp3',
            extension: 'mp3',
            size: 1_000_000,
            modifiedAt: time(),
            hash: 'cafebabe',
        );

        $album = Album::create($libraryId, 'Test Album', 'Studio');
        $metadata = (new ExtractedMetadata())
            ->setTitle('Track')
            ->setDuration(180.0)
            ->setArtist('Test Artist');

        $albumPort = $this->createMock(AlbumPortInterface::class);
        $albumPort->method('findByTitleAndLibrary')->willReturn($album);

        $songPort = $this->createMock(SongPortInterface::class);
        $songPort->method('findByHash')->willReturn(null);
        $songPort->method('persist')->willThrowException(new RuntimeException('Database write failed'));

        $genrePort = $this->createMock(GenrePortInterface::class);
        $moviePort = $this->createMock(MoviePortInterface::class);
        $videoRepository = $this->createMock(VideoRepositoryInterface::class);

        $metadataReader = $this->createMock(MetadataContentReaderPortInterface::class);
        $metadataReader->method('readMetadata')->willReturn($metadata);

        $ffprobe = new FFprobeAdapter(new JsonEncoder());
        $messageBus = $this->createMock(MessageBusInterface::class);
        $logger = new NullLogger();

        $handler = new FilesDiscoveredHandler(
            $albumPort,
            $genrePort,
            $songPort,
            $moviePort,
            $videoRepository,
            $metadataReader,
            $ffprobe,
            $messageBus,
            $logger,
        );

        $message = new FilesDiscovered($libraryId, 'music', $directory, [$fileOne, $fileTwo]);

        try {
            $handler($message);
            $this->fail('Expected RuntimeException with failure list.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to process 2 file(s)', $e->getMessage());
            $this->assertStringContainsString('/music/Test Album/01-track.mp3', $e->getMessage());
            $this->assertStringContainsString('/music/Test Album/02-track.mp3', $e->getMessage());
        }
    }
}
