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
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\DiscoveredFileObservations;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Port\AudioProbePortInterface;
use App\Transcode\Application\Port\VideoProbePortInterface;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

final class FilesDiscoveredTechnicalMetadataTest extends TestCase
{
    use DiscoveredFileObservations;

    public function testNewSongUsesProbedStreamMetadata(): void
    {
        $library = Uuid::v7();
        $album = Album::create($library, 'Album', 'Studio');
        $album->setCoverImage(Uuid::v7());
        $file = $this->observedFile(new DiscoveredFile('/music/Album/song.m4a', 'Album/song.m4a', 'm4a', 100, 10, 'hash'));
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByTitleAndLibrary')->willReturn($album);
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects($this->once())->method('persist')->willReturnCallback(static function (Song $song): void {
            self::assertSame('aac', $song->getCodec());
            self::assertSame('mov,mp4,m4a,3gp,3g2,mj2', $song->getContainerFormat());
            self::assertSame(3, $song->getStreamIndex());
            self::assertSame(48000, $song->getSampleRate());
            self::assertSame(128000, $song->getBitrate());
            self::assertSame(2, $song->getChannels());
            self::assertSame(20.5, $song->getLength(), 'The length is measured from the audio, not taken from the tags.');
        });
        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadata->method('readMetadata')->willReturn((new ExtractedMetadata())->setTitle('Track')->setDuration(17.37)->setSampleRate(1));
        $probe = $this->createStub(AudioProbePortInterface::class);
        $probe->method('probeAudio')->willReturn(new AudioProbeResult('mov,mp4,m4a,3gp,3g2,mj2', 3, 'aac', 2, 48000, 128000, 20.5));
        $handler = new FilesDiscoveredHandler($albums, $this->createStub(GenrePortInterface::class), $songs,
            $this->createStub(MoviePortInterface::class), $this->createStub(VideoRepositoryInterface::class), $metadata,
            $this->createStub(VideoProbePortInterface::class), $this->createStub(MessageBusInterface::class),
            $this->createStub(LyricsFetchRequestInterface::class), $this->createStub(AlbumMetadataSyncRequestInterface::class), new NullLogger(),
            $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class)), new PassThroughTransaction(), $probe,
            $this->artistIdentitiesStub());

        $handler(new FilesDiscovered($library, 'music', '/music/Album', [$file]));
    }

    #[DataProvider('refreshCases')]
    public function testExistingSongRefreshesOnlyCurrentTechnicalMetadata(string $case): void
    {
        $library = Uuid::v7();
        $album = Album::create($library, 'Album', 'Studio');
        $album->setCoverImage(Uuid::v7());
        $file = $this->observedFile(new DiscoveredFile('/music/Album/song.aac', 'Album/song.aac', 'aac', 100, 10, 'hash'));
        $song = Song::create($album->getId(), 'Edited title', $file->absolutePath, 100, 'audio/aac', length: 17.37, hash: 'hash');
        $fresh = Song::reconstitute(clone $song->getState());
        if ($case === 'changed source') {
            $fresh->updateAudioMetadata(hash: 'new-hash');
        }
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByTitleAndLibrary')->willReturn($album);
        $songs = $this->createMock(SongPortInterface::class);
        $songs->method('findByHash')->willReturn($song);
        $songs->method('findFreshByUuid')->willReturn($fresh);
        $songs->expects($case === 'current' ? $this->once() : $this->never())->method('persist')->with($fresh);
        $songs->expects($this->never())->method('addArtistCredit');
        $metadata = $this->createMock(MetadataContentReaderPortInterface::class);
        $metadata->expects($this->once())->method('readMetadata')->willReturn(null);
        $lyrics = $this->createMock(LyricsFetchRequestInterface::class);
        $lyrics->expects($this->never())->method('requestFetch');
        $probe = $this->createMock(AudioProbePortInterface::class);
        $probe->expects($this->once())->method('probeAudio')->with($file->absolutePath)->willReturnCallback(function () use ($file, $case): AudioProbeResult {
            if ($case === 'changed observation') {
                $this->availableHashes[$file->absolutePath] = 'new-hash';
            }

            return new AudioProbeResult('aac', 3, 'aac', 2, 48000, 128000, 20.5);
        });
        $handler = new FilesDiscoveredHandler($albums, $this->createStub(GenrePortInterface::class), $songs,
            $this->createStub(MoviePortInterface::class), $this->createStub(VideoRepositoryInterface::class), $metadata,
            $this->createStub(VideoProbePortInterface::class), $this->createStub(MessageBusInterface::class), $lyrics,
            $this->createStub(AlbumMetadataSyncRequestInterface::class), new NullLogger(),
            $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class)), new PassThroughTransaction(), $probe,
            $this->artistIdentitiesStub());

        $handler(new FilesDiscovered($library, 'music', '/music/Album', [$file]));

        self::assertSame('Edited title', $fresh->getTitle());
        self::assertSame(17.37, $fresh->getLength());
        self::assertSame($case === 'current' ? 'aac' : null, $fresh->getContainerFormat());
        self::assertSame($case === 'current' ? 3 : null, $fresh->getStreamIndex());
    }

    #[DataProvider('refreshCases')]
    public function testExistingVideoRefreshesOnlyCurrentTechnicalMetadata(string $case): void
    {
        $library = Uuid::v7();
        $file = $this->observedFile(new DiscoveredFile('/movies/Movie/clip.mkv', 'Movie/clip.mkv', 'mkv', 100, 10, 'hash'));
        $video = Video::create($file->absolutePath, 'hash');
        $fresh = Video::reconstitute(clone $video->getState());
        if ($case === 'changed source') {
            $fresh->getState()->hash = 'new-hash';
        }
        $videos = $this->createMock(VideoRepositoryInterface::class);
        $videos->method('findByHash')->willReturn($video);
        $videos->method('findFreshByUuid')->willReturn($fresh);
        $videos->expects($case === 'current' ? $this->once() : $this->never())->method('save')->with($fresh);
        $probe = $this->createMock(VideoProbePortInterface::class);
        $probe->expects($this->once())->method('probeVideo')->with($file->absolutePath)->willReturnCallback(function () use ($file, $case): VideoProbeResult {
            if ($case === 'changed observation') {
                $this->availableHashes[$file->absolutePath] = 'new-hash';
            }

            return VideoProbeResult::fromProbeOutput(['format' => ['format_name' => 'matroska'], 'streams' => [
                ['index' => 4, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 320, 'height' => 180],
                ['index' => 7, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ]]);
        });
        $movies = $this->createMock(MoviePortInterface::class);
        $movie = Movie::create($library, 'Movie');
        $movie->addVideo($video->getId());
        $movies->method('findByTitleAndLibrary')->willReturn($movie);
        $movies->expects($this->never())->method('persist');
        $handler = new FilesDiscoveredHandler($this->createStub(AlbumPortInterface::class), $this->createStub(GenrePortInterface::class),
            $this->createStub(SongPortInterface::class), $movies, $videos, $this->createStub(MetadataContentReaderPortInterface::class),
            $probe, $this->createStub(MessageBusInterface::class), $this->createStub(LyricsFetchRequestInterface::class),
            $this->createStub(AlbumMetadataSyncRequestInterface::class), new NullLogger(),
            $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class)), new PassThroughTransaction(),
            $this->createStub(AudioProbePortInterface::class), $this->artistIdentitiesStub());

        $handler(new FilesDiscovered($library, 'movie', '/movies/Movie', [$file]));

        self::assertSame($case === 'current' ? 4 : null, $fresh->getProbe()['videoStreamIndex'] ?? null);
        self::assertSame($case === 'current' ? 7 : null, $fresh->getProbe()['audioStreams'][0]['streamIndex'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function refreshCases(): iterable
    {
        yield 'current' => ['current'];
        yield 'source changed during probe' => ['changed source'];
        yield 'observation changed during probe' => ['changed observation'];
    }
}
