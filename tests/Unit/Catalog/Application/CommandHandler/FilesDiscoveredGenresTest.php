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
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\DiscoveredFileObservations;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * A batch finds or creates all its genres in one call inside its transaction, before it writes
 * any song, and links each song to the genres that call returned.
 */
final class FilesDiscoveredGenresTest extends TestCase
{
    use DiscoveredFileObservations;

    private const string DIRECTORY = '/music/Genre Album';

    private \stdClass $state;
    private PassThroughTransaction $transaction;

    protected function setUp(): void
    {
        $this->state = (object) ['events' => [], 'resolved' => [], 'genres' => [], 'links' => [], 'titles' => []];
        $this->transaction = new PassThroughTransaction();
    }

    public function testABatchResolvesEveryGenreOnceInsideItsTransactionBeforeWritingSongs(): void
    {
        $tags = ['01 One' => ['Rock', 'Pop'], '02 Two' => ['rock']];

        $this->handler($tags)($this->message('01 One', '02 Two'));

        self::assertSame([[['Rock', 'Pop', 'rock'], true]], $this->state->resolved, 'One call, inside the transaction, with every tag.');
        self::assertSame(
            ['genres resolved', 'song persisted', 'genre linked', 'genre linked', 'song persisted', 'genre linked'],
            $this->state->events,
        );
        self::assertSame(['01 One' => ['rock', 'pop'], '02 Two' => ['rock']], $this->linkedGenres());
    }

    public function testAFileReplacedSinceItsTagsWereReadCreatesNoneOfItsGenres(): void
    {
        $tags = ['01 One' => ['Rock'], '02 Two' => ['Jazz']];
        $replaced = self::DIRECTORY . '/02 Two.flac';

        $this->handler($tags, $replaced)($this->message('01 One', '02 Two'));

        self::assertSame([[['Rock'], true]], $this->state->resolved);
        self::assertSame(['01 One' => ['rock']], $this->linkedGenres());
    }

    /**
     * @param array<string, list<string>> $tags     the genre tags of each file, by file name
     * @param string|null                 $replaced a file that changes on disk while its tags are read
     */
    private function handler(array $tags, ?string $replaced = null): FilesDiscoveredHandler
    {
        $state = $this->state;
        $transaction = $this->transaction;
        $album = Album::create(Uuid::v7(), 'Genre Album', 'Studio');
        $album->setCoverImage(Uuid::v7());
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByTitleAndLibrary')->willReturn($album);

        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('persist')->willReturnCallback(static function (Song $song) use ($state): void {
            $state->titles[$song->getId()->toString()] = $song->getTitle();
            $state->events[] = 'song persisted';
        });

        // The fake gives names that differ only in case one genre, as the repository does by slug.
        $genres = $this->createStub(GenrePortInterface::class);
        $genres->method('findOrCreateForImport')->willReturnCallback(static function (array $names) use ($state, $transaction): array {
            $state->resolved[] = [$names, $transaction->active];
            $state->events[] = 'genres resolved';
            $ids = [];
            foreach ($names as $name) {
                $ids[$name] = $state->genres[strtolower($name)] ??= Uuid::v7();
            }

            return $ids;
        });
        $genres->method('addSongToGenre')->willReturnCallback(static function (Uuid $genreId, Uuid $songId) use ($state): bool {
            $state->links[] = [$songId->toString(), $genreId->toString()];
            $state->events[] = 'genre linked';

            return true;
        });

        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadata->method('readMetadata')->willReturnCallback(function (string $path) use ($tags, $replaced): ExtractedMetadata {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if ($path === $replaced) {
                $this->availableHashes[$path] = 'replaced';
            }

            return (new ExtractedMetadata())->setTitle($name)->setAlbum('Genre Album')->setGenre($tags[$name]);
        });

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
            $this->createStub(LyricsFetchRequestInterface::class),
            $this->createStub(AlbumMetadataSyncRequestInterface::class),
            new NullLogger(),
            $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class)),
            $transaction,
            audioProbe: $this->audioProbeStub(),
            artistIdentities: $this->artistIdentitiesStub(),
        );
    }

    private function message(string ...$names): FilesDiscovered
    {
        $files = array_map(
            fn (string $name): DiscoveredFile => $this->observedFile(new DiscoveredFile(
                self::DIRECTORY . '/' . $name . '.flac',
                'Genre Album/' . $name . '.flac',
                'flac',
                1_000_000,
                1_700_000_000,
                'hash-' . $name,
            )),
            $names,
        );

        return new FilesDiscovered(Uuid::v7(), 'music', self::DIRECTORY, $files);
    }

    /** @return array<string, list<string>> the lower-case genre names each song was linked to, by song title */
    private function linkedGenres(): array
    {
        $names = [];
        foreach ($this->state->genres as $name => $id) {
            $names[$id->toString()] = $name;
        }
        $linked = [];
        foreach ($this->state->links as [$songId, $genreId]) {
            $linked[$this->state->titles[$songId]][] = $names[$genreId];
        }

        return $linked;
    }
}
