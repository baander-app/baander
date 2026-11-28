<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistSyncRequestInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Port\VideoMatchRequestInterface;
use App\Catalog\Application\Service\VideoFileIngest;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\DiscoveredFileObservations;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Exception\UnsupportedMediaContainerException;
use App\Transcode\Application\Port\AudioProbePortInterface;
use App\Transcode\Application\Port\VideoProbePortInterface;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use Baander\MediaMetadata\Model\ExtractedMetadata;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A library file whose content is not a container of its kind, such as a DASH manifest named like
 * a song or a film, is not imported: the import reports the format and marks the file's index row
 * unavailable, which also stops playback of a song or video imported from it before.
 */
final class FilesDiscoveredRefusedContainerTest extends TestCase
{
    use DiscoveredFileObservations;

    public function testARefusedSongIsReportedWithItsFormatAndMarkedUnavailableWhileTheGenuineOneImports(): void
    {
        $manifest = $this->song('/music/Album/01 - Borrowed Track.mp3', 'hash-manifest');
        $genuine = $this->song('/music/Album/02 - Own Track.mp3', 'hash-genuine');
        $message = new FilesDiscovered(Uuid::v7(), 'music', '/music/Album', [$manifest, $genuine]);
        $marked = [];
        $persisted = [];
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('persist')->willReturnCallback(static function (Song $song) use (&$persisted): void {
            $persisted[] = $song->getPath();
        });

        $failure = $this->importFailure($this->handler(
            $this->mediaFiles($marked),
            songs: $songs,
            audioProbe: $this->audioProbe([$manifest->absolutePath => 'dash']),
        ), $message);

        self::assertSame([$genuine->absolutePath], $persisted);
        self::assertSame([[$message->libraryId, [$manifest->absolutePath]]], $marked);
        self::assertStringContainsString($manifest->absolutePath . ': Not a supported media container: dash.', $failure);
        self::assertStringNotContainsString($genuine->absolutePath, $failure);
    }

    public function testARescanRefusesASongImportedFromAManifestBeforeImportsCheckedTheContainer(): void
    {
        $manifest = $this->song('/music/Album/01 - Borrowed Track.mp3', 'hash-manifest');
        $message = new FilesDiscovered(Uuid::v7(), 'music', '/music/Album', [$manifest]);
        $stored = Song::create(Uuid::v7(), 'Borrowed Track', $manifest->absolutePath, 500, 'audio/mpeg', hash: $manifest->hash);
        $marked = [];
        $songs = $this->createMock(SongPortInterface::class);
        $songs->method('findByHash')->willReturn($stored);
        $songs->expects($this->never())->method('persist');

        $failure = $this->importFailure($this->handler(
            $this->mediaFiles($marked),
            songs: $songs,
            audioProbe: $this->audioProbe([$manifest->absolutePath => 'dash']),
        ), $message);

        self::assertSame([[$message->libraryId, [$manifest->absolutePath]]], $marked);
        self::assertStringContainsString('Not a supported media container: dash.', $failure);
    }

    public function testARescanProbesAVideoWhoseStoredFormatIsNoVideoContainerAndRefusesIt(): void
    {
        $manifest = $this->observedFile(new DiscoveredFile(
            '/movies/Holiday Clip (2021)/Holiday Clip (2021).mkv',
            'Holiday Clip (2021)/Holiday Clip (2021).mkv',
            'mkv',
            500,
            1_700_000_000,
            'hash-manifest',
        ));
        $message = new FilesDiscovered(Uuid::v7(), 'movie', '/movies/Holiday Clip (2021)', [$manifest]);
        $stored = Video::create($manifest->absolutePath, $manifest->hash, 6, 360, 640);
        $stored->updateProbe(['containerFormat' => 'dash', 'videoStreamIndex' => 0, 'chapters' => [], 'audioStreams' => []]);
        $videos = $this->createMock(VideoRepositoryInterface::class);
        $videos->method('findByHash')->willReturn($stored);
        $videos->expects($this->never())->method('save');
        $probed = [];
        $videoProbe = $this->createStub(VideoProbePortInterface::class);
        $videoProbe->method('probeVideo')->willReturnCallback(static function (string $path) use (&$probed): VideoProbeResult {
            $probed[] = $path;

            throw new UnsupportedMediaContainerException('dash');
        });
        $marked = [];

        $failure = $this->importFailure($this->handler($this->mediaFiles($marked), videos: $videos, videoProbe: $videoProbe), $message);

        self::assertSame([$manifest->absolutePath], $probed);
        self::assertSame([[$message->libraryId, [$manifest->absolutePath]]], $marked);
        self::assertStringContainsString('Not a supported media container: dash.', $failure);
    }

    public function testAKnownVideoInATableContainerIsNotProbedAgain(): void
    {
        $film = $this->observedFile(new DiscoveredFile('/movies/Film/Film.mkv', 'Film/Film.mkv', 'mkv', 500, 1_700_000_000, 'hash-film'));
        $stored = Video::create($film->absolutePath, $film->hash, 6, 360, 640);
        $stored->updateProbe(['containerFormat' => 'matroska,webm', 'videoStreamIndex' => 0, 'chapters' => [], 'audioStreams' => []]);
        $videos = $this->createStub(VideoRepositoryInterface::class);
        $videos->method('findByHash')->willReturn($stored);
        $videoProbe = $this->createMock(VideoProbePortInterface::class);
        $videoProbe->expects($this->never())->method('probeVideo');
        $movies = $this->createStub(MovieRepositoryInterface::class);
        $movies->method('findByPlacementFolder')->willReturn(Movie::create(Uuid::v7(), 'Film'));
        $marked = [];

        $this->handler($this->mediaFiles($marked), movies: $movies, videos: $videos, videoProbe: $videoProbe)(
            new FilesDiscovered(Uuid::v7(), 'movie', '/movies/Film', [$film]),
        );

        self::assertSame([], $marked);
    }

    /** @param list<array{Uuid, list<string>}> $marked */
    private function mediaFiles(array &$marked): LibraryMediaFilesInterface
    {
        $mediaFiles = $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class));
        $mediaFiles->method('markMissingUnavailable')->willReturn([]);
        $mediaFiles->method('markUnavailable')->willReturnCallback(static function (Uuid $libraryId, array $paths) use (&$marked): void {
            $marked[] = [$libraryId, $paths];
        });

        return $mediaFiles;
    }

    /** @param array<string, string> $refusedFormats the format each refused path holds */
    private function audioProbe(array $refusedFormats): AudioProbePortInterface
    {
        $probe = $this->createStub(AudioProbePortInterface::class);
        $probe->method('probeAudio')->willReturnCallback(static function (string $path) use ($refusedFormats): AudioProbeResult {
            if (isset($refusedFormats[$path])) {
                throw new UnsupportedMediaContainerException($refusedFormats[$path]);
            }

            return new AudioProbeResult('mp3', 0, 'mp3', 2, 44100, 320000, 180.0);
        });

        return $probe;
    }

    private function importFailure(FilesDiscoveredHandler $handler, FilesDiscovered $message): string
    {
        try {
            $handler($message);
        } catch (RuntimeException $failure) {
            return $failure->getMessage();
        }

        self::fail('The import must report the refused file.');
    }

    private function song(string $path, string $hash): DiscoveredFile
    {
        return $this->observedFile(new DiscoveredFile($path, 'Album/' . basename($path), 'mp3', 500, 1_700_000_000, $hash));
    }

    private function handler(
        LibraryMediaFilesInterface $mediaFiles,
        ?SongPortInterface $songs = null,
        ?MovieRepositoryInterface $movies = null,
        ?VideoRepositoryInterface $videos = null,
        ?AudioProbePortInterface $audioProbe = null,
        ?VideoProbePortInterface $videoProbe = null,
    ): FilesDiscoveredHandler {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByTitleAndLibrary')->willReturn(Album::create(Uuid::v7(), 'Album', 'Studio'));
        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        // A tagless file reads as empty metadata rather than as no metadata.
        $metadata->method('readMetadata')->willReturn(new ExtractedMetadata());
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $videoIngestLogger = new NullLogger();
        $videoIngestTransaction = new PassThroughTransaction();
        return new FilesDiscoveredHandler(
            $albums,
            $this->createStub(GenrePortInterface::class),
            $songs ?? $this->createStub(SongPortInterface::class),
            $this->movieIngest($movies ?? $this->createStub(MovieRepositoryInterface::class), $videos ?? $this->createStub(VideoRepositoryInterface::class), $mediaFiles),
            new VideoFileIngest(
                $videos ?? $this->createStub(VideoRepositoryInterface::class),
                $videoProbe ?? $this->createStub(VideoProbePortInterface::class),
                $mediaFiles,
                $videoIngestTransaction,
                $videoIngestLogger,
            ),
            $metadata,
            $bus,
            $this->createStub(LyricsFetchRequestInterface::class),
            $this->createStub(AlbumMatchRequestInterface::class),
            $videoIngestLogger,
            $mediaFiles,
            $videoIngestTransaction,
            audioProbe: $audioProbe ?? $this->audioProbeStub(),
            artistIdentities: $this->artistIdentitiesStub(),
            artistSyncs: $this->createStub(ArtistSyncRequestInterface::class),
            tvLibraryIngest: $this->unusedTvIngest(),
            videoMatches: $this->createStub(VideoMatchRequestInterface::class),
        );
    }
}
