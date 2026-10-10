<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Service\MovieLibraryIngest;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryProvisioningInterface;
use App\Library\Application\Port\ProvisionedLibraryScan;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class MovieLibraryIngestTest extends TestCase
{
    public function testMissingExistingLibraryReturnsNull(): void
    {
        $provisioning = $this->createMock(LibraryProvisioningInterface::class);
        $provisioning->expects($this->once())->method('scanExistingLibrary')->with('e2e-test-movies')->willReturn(null);
        $movies = $this->createMock(MoviePortInterface::class);
        $movies->expects($this->never())->method('persist');

        self::assertNull($this->ingest($provisioning, $movies, $this->createStub(VideoRepositoryInterface::class))->ingestExistingLibrary('e2e-test-movies'));
    }

    public function testExistingLibraryDiscoveriesAreHandledAndResolvedToVideoIds(): void
    {
        $libraryId = Uuid::v7();
        $video = Video::create('/srv/baander-e2e/Fixture Movie/clip.mp4', 'fixture-hash');
        $file = new DiscoveredFile('/srv/baander-e2e/Fixture Movie/clip.mp4', 'Fixture Movie/clip.mp4', 'mp4', 1024, 1_700_000_000, 'fixture-hash');
        $provisioning = $this->createStub(LibraryProvisioningInterface::class);
        $provisioning->method('scanExistingLibrary')->willReturn(new ProvisionedLibraryScan($libraryId, 'E2E Test Movies', false, [
            new FilesDiscovered($libraryId, 'movie', '/srv/baander-e2e/Fixture Movie', [$file]),
        ]));
        $videos = $this->createStub(VideoRepositoryInterface::class);
        $videos->method('findByHash')->willReturnMap([['fixture-hash', $video]]);
        $persisted = [];
        $movies = $this->createStub(MoviePortInterface::class);
        $movies->method('persist')->willReturnCallback(static function (Movie $movie) use (&$persisted): void {
            $persisted[] = $movie;
        });

        $result = $this->ingest($provisioning, $movies, $videos)->ingestExistingLibrary('e2e-test-movies');

        self::assertNotNull($result);
        self::assertSame($libraryId->toString(), $result->libraryId);
        self::assertFalse($result->libraryCreated);
        self::assertSame([$video->getId()->toString()], $result->videoIds);
        self::assertNotSame([], $persisted, 'The discovered directory must reach the Catalog handler.');
        self::assertSame('Fixture Movie', $persisted[0]->getTitle());
        self::assertSame([$video->getId()->toString()], $persisted[0]->getVideoIds());
    }

    private function ingest(LibraryProvisioningInterface $provisioning, MoviePortInterface $movies, VideoRepositoryInterface $videos): MovieLibraryIngest
    {
        $handler = new FilesDiscoveredHandler(
            $this->createStub(AlbumPortInterface::class), $this->createStub(GenrePortInterface::class),
            $this->createStub(SongPortInterface::class), $movies,
            $videos, $this->createStub(MetadataContentReaderPortInterface::class), new FFprobeAdapter(new JsonEncoder()),
            $this->createStub(MessageBusInterface::class), $this->createStub(LyricsFetchRequestInterface::class), $this->createStub(AlbumMetadataSyncRequestInterface::class), new NullLogger(),
            $this->createStub(LibraryMediaFilesInterface::class),
        );

        return new MovieLibraryIngest($provisioning, $handler, $videos);
    }
}
