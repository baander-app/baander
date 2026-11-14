<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Port\ArtistIdentityResolverInterface;
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
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\Service\LyricsFetchRequester;
use App\Lyrics\Application\Settings\LyricsSettingDefinitions;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\DiscoveredFileObservations;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Port\AudioProbePortInterface;
use App\Transcode\Application\Port\VideoProbePortInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A file whose content changed at the path of a stored song was retagged: the scan updates that song
 * in place instead of creating a second one. The song keeps its ID, album and lyrics, and takes its
 * unlocked tag fields, its technical fields, its length and all its artist credits from the file.
 * The handler runs over fakes that keep albums, songs, credits, artists, file tags and probe results
 * in memory.
 */
final class FilesDiscoveredRetagTest extends TestCase
{
    use DiscoveredFileObservations;

    private const string DIRECTORY = '/music/Disturbed/Ten Thousand Fists';
    private const string PATH = self::DIRECTORY . '/03 Track.flac';

    private \stdClass $state;
    private PassThroughTransaction $transaction;
    private Uuid $libraryId;

    protected function setUp(): void
    {
        $this->transaction = new PassThroughTransaction();
        $this->libraryId = Uuid::v7();
        $this->state = (object) [
            'albums' => [],
            'createdAlbums' => [],
            'songs' => [],
            'credits' => [],
            'artists' => [],
            'tags' => [],
            'lengths' => [],
            'probeFailures' => [],
            'autoFetch' => false,
            'lyricsRequests' => [],
        ];
    }

    public function testARetaggedFileUpdatesItsSongInPlaceAndItsCreditsFollowTheNewTags(): void
    {
        $album = $this->storedAlbum('Ten Thousand Fists');
        $song = $this->storedSong($album, lyrics: "[00:01.00]Line\n");
        // An administrator credited the song by hand to "X", and added a featured artist.
        $this->state->credits[$song->getId()->toString()] = [
            [Uuid::v7()->toString(), 'primary', 'X'],
            [Uuid::v7()->toString(), 'featured', 'Z'],
        ];
        // The file is then retagged. Its album tag names an album the catalog does not hold.
        $this->state->tags[self::PATH] = ['title' => 'Stricken', 'track' => 3, 'year' => 2005, 'album' => 'Ten Thousand Fists (Deluxe)', 'artists' => ['Y']];
        $this->state->lengths[self::PATH] = 245.2;

        $this->handler()($this->retaggedFile($song, 'hash-retagged', 1_200_000));

        self::assertSame([$song->getId()->toString()], array_keys($this->state->songs), 'No second song exists for the path.');
        self::assertSame([], $this->state->createdAlbums, 'A retagged file creates no album.');
        $stored = $this->storedSongById($song);
        self::assertSame(
            ['Stricken', 3, 2005, 'hash-retagged', 1_200_000, 245.2, 900000],
            [$stored->getTitle(), $stored->getTrack(), $stored->getYear(), $stored->getHash(), $stored->getSize(), $stored->getLength(), $stored->getBitrate()],
        );
        self::assertTrue($stored->getPublicId()->equals($song->getPublicId()));
        self::assertTrue($stored->getAlbumId()->equals($album->getId()), 'The song stays in its album.');
        self::assertSame("[00:01.00]Line\n", $stored->getLyrics());
        self::assertSame([$song->getId()->toString() => [[$this->artist('Y'), 'primary', 'Y']]], $this->state->credits, 'The new tag credit replaces every credit, hand-made ones too.');
    }

    public function testARetagKeepsALockedTitleAndStillReplacesTheCredits(): void
    {
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'), title: 'Hand-picked title', lockedFields: ['title']);
        $this->state->credits[$song->getId()->toString()] = [[Uuid::v7()->toString(), 'primary', 'X']];
        $this->state->tags[self::PATH] = ['title' => 'Stricken', 'year' => 2005, 'album' => 'Ten Thousand Fists', 'artists' => ['Y']];

        $this->handler()($this->retaggedFile($song));

        $stored = $this->storedSongById($song);
        self::assertSame('Hand-picked title', $stored->getTitle());
        self::assertSame(2005, $stored->getYear(), 'An unlocked field takes the tag.');
        self::assertSame([$song->getId()->toString() => [[$this->artist('Y'), 'primary', 'Y']]], $this->state->credits);
    }

    public function testARetagWhoseNewTagsNameNoArtistLeavesTheSongWithoutCredits(): void
    {
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'));
        $this->state->credits[$song->getId()->toString()] = [[Uuid::v7()->toString(), 'primary', 'X']];
        $this->state->tags[self::PATH] = ['title' => 'Stricken', 'album' => 'Ten Thousand Fists'];

        $this->handler()($this->retaggedFile($song));

        self::assertSame([], $this->state->credits[$song->getId()->toString()] ?? []);
        self::assertSame('Stricken', $this->storedSongById($song)->getTitle());
    }

    public function testARetagThatKeepsTheArtistGivesTheSongTheSameCreditAgain(): void
    {
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'));
        $disturbed = $this->state->artists['Disturbed'] = Uuid::v7();
        $this->state->credits[$song->getId()->toString()] = [[$disturbed->toString(), 'primary', 'Disturbed']];
        $this->state->tags[self::PATH] = ['title' => 'Stricken', 'album' => 'Ten Thousand Fists', 'artists' => ['Disturbed']];

        $this->handler()($this->retaggedFile($song));

        self::assertSame([$song->getId()->toString() => [[$disturbed->toString(), 'primary', 'Disturbed']]], $this->state->credits);
        self::assertSame('Stricken', $this->storedSongById($song)->getTitle());
    }

    public function testARetaggedFileThatCannotBeProbedFailsAndLeavesItsSongAsItIs(): void
    {
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'));
        $credits = [$song->getId()->toString() => [[Uuid::v7()->toString(), 'primary', 'X']]];
        $this->state->credits = $credits;
        $this->state->tags[self::PATH] = ['title' => 'Stricken', 'album' => 'Ten Thousand Fists', 'artists' => ['Y']];
        $this->state->probeFailures[self::PATH] = true;

        try {
            $this->handler()($this->retaggedFile($song));
            self::fail('A retagged file that cannot be probed fails the message.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(self::PATH, $e->getMessage());
        }

        $stored = $this->storedSongById($song);
        self::assertSame([$song->getTitle(), $song->getHash()], [$stored->getTitle(), $stored->getHash()]);
        self::assertSame($credits, $this->state->credits);
    }

    #[DataProvider('automaticFetch')]
    public function testARetaggedSongWithoutLyricsRequestsAFetchOnlyWhileAutomaticFetchIsOn(bool $autoFetch): void
    {
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'));
        $this->state->tags[self::PATH] = ['title' => 'Stricken', 'album' => 'Ten Thousand Fists', 'artists' => ['Disturbed']];
        $this->state->autoFetch = $autoFetch;

        $this->handler()($this->retaggedFile($song));

        self::assertSame($autoFetch ? [$song->getId()->toString()] : [], $this->state->lyricsRequests);
    }

    /** @return iterable<string, array{bool}> */
    public static function automaticFetch(): iterable
    {
        yield 'automatic fetch on' => [true];
        yield 'automatic fetch off' => [false];
    }

    private function handler(): FilesDiscoveredHandler
    {
        $state = $this->state;
        $transaction = $this->transaction;

        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByTitleAndLibrary')->willReturnCallback(
            static fn (string $title): ?Album => array_find($state->albums, static fn (Album $album): bool => $album->getTitle() === $title),
        );
        $albums->method('findByUuid')->willReturnCallback(static fn (Uuid $id): ?Album => $state->albums[$id->toString()] ?? null);
        $albums->method('persist')->willReturnCallback(static function (Album $album) use ($state): void {
            $album->setCoverImage(Uuid::v7());
            $state->albums[$album->getId()->toString()] = $album;
            $state->createdAlbums[] = $album->getTitle();
        });

        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByHash')->willReturnCallback(static function (string $hash) use ($state): ?Song {
            $song = array_find($state->songs, static fn (Song $song): bool => $song->getHash() === $hash);

            return $song === null ? null : Song::reconstitute(clone $song->getState());
        });
        $songs->method('findByPathsInLibrary')->willReturnCallback(static function (Uuid $libraryId, array $paths) use ($state): array {
            $found = [];
            foreach ($state->songs as $song) {
                if (in_array($song->getPath(), $paths, true)) {
                    $found[$song->getPath()] = Song::reconstitute(clone $song->getState());
                }
            }

            return $found;
        });
        $songs->method('findFreshByUuid')->willReturnCallback(static function (Uuid $id) use ($state, $transaction): ?Song {
            self::assertTrue($transaction->active, 'A stored song is read again inside the transaction that updates it.');
            $song = $state->songs[$id->toString()] ?? null;

            return $song === null ? null : Song::reconstitute(clone $song->getState());
        });
        $songs->method('findByAlbumSortedByTrack')->willReturnCallback(static fn (Uuid $albumId): array => array_values(array_filter(
            $state->songs,
            static fn (Song $song): bool => $song->getAlbumId()->equals($albumId),
        )));
        $songs->method('persist')->willReturnCallback(static function (Song $song) use ($state): void {
            $state->songs[$song->getId()->toString()] = Song::reconstitute(clone $song->getState());
        });
        $songs->method('findCreditedSongIds')->willReturnCallback(static fn (array $songIds): array => array_values(array_filter(
            array_map(static fn (Uuid $id): string => $id->toString(), $songIds),
            static fn (string $id): bool => ($state->credits[$id] ?? []) !== [],
        )));
        $songs->method('removeArtistCredits')->willReturnCallback(static function (Uuid $songId) use ($state, $transaction): void {
            self::assertTrue($transaction->active, 'Credits are replaced inside the transaction that updates the song.');
            unset($state->credits[$songId->toString()]);
        });
        $songs->method('addArtistCredit')->willReturnCallback(static function (Uuid $songId, Uuid $artistId, string $role, string $creditedName) use ($state): void {
            foreach ($state->credits[$songId->toString()] ?? [] as [$creditedArtist, $creditedRole]) {
                if ($creditedArtist === $artistId->toString() && $creditedRole === $role) {
                    throw new RuntimeException('duplicate key value violates unique constraint "uniq_artist_song_artist_id_song_id_role"');
                }
            }
            $state->credits[$songId->toString()][] = [$artistId->toString(), $role, $creditedName];
        });

        $resolver = $this->createStub(ArtistIdentityResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (array $identities) use ($state): array {
            $resolved = [];
            foreach ($identities as $identity) {
                \assert($identity instanceof ArtistIdentity);
                $resolved[$identity->key()] = $state->artists[$identity->name] ??= Uuid::v7();
            }

            return $resolved;
        });

        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadata->method('readMetadata')->willReturnCallback(static function (string $path) use ($state, $transaction): ExtractedMetadata {
            self::assertFalse($transaction->active, 'Tags are read outside any transaction.');
            $tags = $state->tags[$path] ?? [];

            return (new ExtractedMetadata())
                ->setTitle($tags['title'] ?? pathinfo($path, PATHINFO_FILENAME))
                ->setTrackNumber($tags['track'] ?? null)
                ->setYear($tags['year'] ?? null)
                ->setAlbum($tags['album'] ?? null)
                ->setArtists($tags['artists'] ?? []);
        });

        $probe = $this->createStub(AudioProbePortInterface::class);
        $probe->method('probeAudio')->willReturnCallback(static function (string $path) use ($state, $transaction): AudioProbeResult {
            self::assertFalse($transaction->active, 'Audio is probed outside any transaction.');
            if (isset($state->probeFailures[$path])) {
                throw new RuntimeException('ffprobe exited with code 1');
            }

            return new AudioProbeResult('flac', 0, 'flac', 2, 44100, 900000, $state->lengths[$path] ?? 213.4);
        });

        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(static fn (string $key): bool|int|string => $key === LyricsSettingDefinitions::AUTO_FETCH ? $state->autoFetch : false);
        $lyricsBus = $this->createStub(MessageBusInterface::class);
        $lyricsBus->method('dispatch')->willReturnCallback(static function (object $message) use ($state): Envelope {
            if ($message instanceof FetchLyricsCommand) {
                $state->lyricsRequests[] = $message->getSongId()->toString();
            }

            return new Envelope($message);
        });

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        return new FilesDiscoveredHandler(
            $albums,
            $this->createStub(GenrePortInterface::class),
            $songs,
            $this->createStub(MoviePortInterface::class),
            $this->createStub(VideoRepositoryInterface::class),
            $metadata,
            $this->createStub(VideoProbePortInterface::class),
            $bus,
            new LyricsFetchRequester($settings, $lyricsBus, new MockClock()),
            $this->createStub(AlbumMetadataSyncRequestInterface::class),
            new NullLogger(),
            $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class)),
            $transaction,
            $probe,
            $resolver,
        );
    }

    private function storedAlbum(string $title): Album
    {
        $album = Album::create($this->libraryId, $title, 'Studio');
        $album->setCoverImage(Uuid::v7());
        $this->state->albums[$album->getId()->toString()] = $album;

        return $album;
    }

    /**
     * A song an earlier scan stored from the file at the test path, before the file was retagged.
     *
     * @param list<string> $lockedFields
     */
    private function storedSong(Album $album, string $title = '03 Track', ?string $lyrics = null, array $lockedFields = []): Song
    {
        $song = Song::create(
            album: $album->getId(),
            title: $title,
            path: self::PATH,
            size: 1_000_000,
            mimeType: 'audio/flac',
            length: 213.4,
            lyrics: $lyrics,
            track: 3,
            hash: 'hash-before-retag',
            bitrate: 320000,
            sampleRate: 44100,
            channels: 2,
            codec: 'flac',
            containerFormat: 'flac',
            streamIndex: 0,
        );
        foreach ($lockedFields as $field) {
            $song->lockField($field);
        }
        $this->state->songs[$song->getId()->toString()] = Song::reconstitute(clone $song->getState());

        return $song;
    }

    private function storedSongById(Song $song): Song
    {
        $stored = $this->state->songs[$song->getId()->toString()] ?? null;
        self::assertNotNull($stored);

        return $stored;
    }

    /** A scan of the directory that found the song's file with new content. */
    private function retaggedFile(Song $song, string $hash = 'hash-retagged', int $size = 1_000_500): FilesDiscovered
    {
        return new FilesDiscovered($this->libraryId, 'music', self::DIRECTORY, [
            $this->observedFile(new DiscoveredFile($song->getPath(), ltrim($song->getPath(), '/'), 'flac', $size, 1_700_000_000, $hash)),
        ]);
    }

    /** The artist the fake resolver found or created for the name. */
    private function artist(string $name): string
    {
        self::assertArrayHasKey($name, $this->state->artists, sprintf('No artist was resolved for "%s".', $name));

        return $this->state->artists[$name]->toString();
    }
}
