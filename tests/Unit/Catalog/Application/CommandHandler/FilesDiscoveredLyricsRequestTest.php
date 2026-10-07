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
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Ingest asks the Lyrics context for one fetch per track it created, and only
 * once the songs are committed, so the asynchronous fetch can find them.
 */
final class FilesDiscoveredLyricsRequestTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-lyrics-request-' . bin2hex(random_bytes(6));
        if (!mkdir($this->directory) && !is_dir($this->directory)) {
            self::fail('Cannot create the music directory fixture.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testTwoNewTracksRequestTwoFetchesAfterTheIngestCommits(): void
    {
        $state = $this->state();
        $lyrics = $this->lyricsFetch($state);
        $lyrics->expects($this->once())->method('requestFetch');

        $this->handler($state, $lyrics)($this->message($this->file('01 One.mp3', 'hash-one'), $this->file('02 Two.mp3', 'hash-two')));

        self::assertSame(['song persisted', 'song persisted', 'songs flushed', 'genres flushed', 'lyrics requested'], $state->events);
        self::assertSame($this->ids($state->visibleSongs), $state->requests[0]);
    }

    public function testTrackWithSidecarLrcFileRequestsNoFetch(): void
    {
        file_put_contents($this->directory . '/01 One.lrc', "[00:01.00]Sidecar line\n");
        $state = $this->state();
        $lyrics = $this->lyricsFetch($state);
        $lyrics->expects($this->once())->method('requestFetch');

        $this->handler($state, $lyrics)($this->message($this->file('01 One.mp3', 'hash-one'), $this->file('02 Two.mp3', 'hash-two')));

        [$withSidecar, $withoutLyrics] = $state->visibleSongs;
        self::assertSame("[00:01.00]Sidecar line\n", $withSidecar->getLyrics());
        self::assertSame([$withoutLyrics->getId()->toString()], $state->requests[0]);
    }

    public function testRescanThatCreatesNoTracksRequestsNothing(): void
    {
        $state = $this->state();
        $state->visibleSongs[] = $this->existingSong('hash-one');
        $lyrics = $this->lyricsFetch($state);
        $lyrics->expects($this->never())->method('requestFetch');

        $this->handler($state, $lyrics)($this->message($this->file('01 One.mp3', 'hash-one')));
    }

    public function testFailedSongFlushRequestsNothing(): void
    {
        $state = $this->state();
        $failure = new RuntimeException('Song flush failed');
        $state->flushFailure = $failure;
        $lyrics = $this->lyricsFetch($state);
        $lyrics->expects($this->never())->method('requestFetch');

        try {
            $this->handler($state, $lyrics)($this->message($this->file('01 One.mp3', 'hash-one')));
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);

            return;
        }
        self::fail('The flush failure must reach Messenger.');
    }

    public function testCommittedTracksAreRequestedBeforeAFailedFileIsReported(): void
    {
        $state = $this->state();
        $state->persistFailures = ['hash-two' => new RuntimeException('Song write failed')];
        $lyrics = $this->lyricsFetch($state);
        $lyrics->expects($this->once())->method('requestFetch');

        try {
            $this->handler($state, $lyrics)($this->message($this->file('01 One.mp3', 'hash-one'), $this->file('02 Two.mp3', 'hash-two')));
            self::fail('The failed file must be reported.');
        } catch (RuntimeException $actual) {
            self::assertStringContainsString('Failed to process 1 file(s)', $actual->getMessage());
        }

        self::assertCount(1, $state->visibleSongs);
        self::assertSame($this->ids($state->visibleSongs), $state->requests[0]);
    }

    public function testEachCommittedBatchRequestsItsOwnTracks(): void
    {
        $state = $this->state();
        $lyrics = $this->lyricsFetch($state);
        $lyrics->expects($this->exactly(2))->method('requestFetch');
        $files = array_map(
            fn (int $track): DiscoveredFile => $this->file(sprintf('%02d Track.mp3', $track), 'hash-' . $track),
            range(1, 51),
        );

        $this->handler($state, $lyrics)($this->message(...$files));

        self::assertCount(50, $state->requests[0]);
        self::assertCount(1, $state->requests[1]);
        self::assertSame($this->ids($state->visibleSongs), [...$state->requests[0], ...$state->requests[1]]);
    }

    private function handler(\stdClass $state, LyricsFetchRequestInterface $lyrics): FilesDiscoveredHandler
    {
        $album = Album::create(Uuid::v7(), 'Test Album', 'Studio');
        $album->setCoverImage(Uuid::v7());
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByTitleAndLibrary')->willReturn($album);

        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByHash')->willReturnCallback(static function (string $hash) use ($state): ?Song {
            foreach ($state->visibleSongs as $song) {
                if ($song->getHash() === $hash) {
                    return $song;
                }
            }

            return null;
        });
        $songs->method('persist')->willReturnCallback(static function (Song $song) use ($state): void {
            $failure = $state->persistFailures[$song->getHash()] ?? null;
            if ($failure !== null) {
                throw $failure;
            }
            $state->pendingSongs[] = $song;
            $state->events[] = 'song persisted';
        });
        $songs->method('flush')->willReturnCallback(static function () use ($state): void {
            if ($state->flushFailure !== null) {
                throw $state->flushFailure;
            }
            $state->visibleSongs = [...$state->visibleSongs, ...$state->pendingSongs];
            $state->pendingSongs = [];
            $state->events[] = 'songs flushed';
        });

        $genres = $this->createStub(GenrePortInterface::class);
        $genres->method('flush')->willReturnCallback(static function () use ($state): void {
            $state->events[] = 'genres flushed';
        });

        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadata->method('readMetadata')->willReturnCallback(
            static fn (string $path): ExtractedMetadata => (new ExtractedMetadata())
                ->setTitle(pathinfo($path, PATHINFO_FILENAME))
                ->setAlbum('Test Album')
                ->setArtist('Test Artist')
                ->setDuration(180.0),
        );

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        return new FilesDiscoveredHandler(
            $albums,
            $genres,
            $songs,
            $this->createStub(MoviePortInterface::class),
            $this->createStub(VideoRepositoryInterface::class),
            $metadata,
            new FFprobeAdapter(new JsonEncoder()),
            $bus,
            $lyrics,
            new NullLogger(),
        );
    }

    /** @return LyricsFetchRequestInterface&MockObject */
    private function lyricsFetch(\stdClass $state): LyricsFetchRequestInterface
    {
        $lyrics = $this->createMock(LyricsFetchRequestInterface::class);
        $lyrics->method('requestFetch')->willReturnCallback(static function (Uuid ...$songIds) use ($state): void {
            $visible = array_map(static fn (Song $song): string => $song->getId()->toString(), $state->visibleSongs);
            $requested = array_map(static fn (Uuid $id): string => $id->toString(), $songIds);
            self::assertSame([], array_diff($requested, $visible), 'Every requested song must already be committed.');
            $state->requests[] = $requested;
            $state->events[] = 'lyrics requested';
        });

        return $lyrics;
    }

    private function state(): \stdClass
    {
        return (object) [
            'pendingSongs' => [],
            'visibleSongs' => [],
            'events' => [],
            'requests' => [],
            'persistFailures' => [],
            'flushFailure' => null,
        ];
    }

    private function file(string $name, string $hash): DiscoveredFile
    {
        return new DiscoveredFile($this->directory . '/' . $name, 'Test Album/' . $name, 'mp3', 1_000_000, 1_700_000_000, $hash);
    }

    private function message(DiscoveredFile ...$files): FilesDiscovered
    {
        return new FilesDiscovered(Uuid::v7(), 'music', $this->directory, array_values($files));
    }

    private function existingSong(string $hash): Song
    {
        return Song::create(album: Uuid::v7(), title: 'Existing', path: $this->directory . '/existing.mp3', size: 1_000_000, mimeType: 'audio/mpeg', hash: $hash);
    }

    /**
     * @param list<Song> $songs
     * @return list<string>
     */
    private function ids(array $songs): array
    {
        return array_map(static fn (Song $song): string => $song->getId()->toString(), $songs);
    }
}
