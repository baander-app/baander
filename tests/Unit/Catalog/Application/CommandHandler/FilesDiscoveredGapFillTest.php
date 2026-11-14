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
use App\Library\Application\Port\LibraryMediaFileCheck;
use App\Library\Application\Port\LibraryMediaFileInspection;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryMediaFileVerdict;
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A rescan delivers files the catalog knows already. Each known song is repaired in place: it gains
 * its tagged artist only when it has no artist credit of any role, and its length is measured from
 * its audio again. The songs stay in the album they are stored in, so a rescan after an album was
 * renamed or merged creates no album. The handler runs over fakes that keep albums, songs, credits,
 * artists, file tags and probe results in memory.
 */
final class FilesDiscoveredGapFillTest extends TestCase
{
    use DiscoveredFileObservations;

    private const string DIRECTORY = '/music/Disturbed/Ten Thousand Fists';

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
            'albumCredits' => [],
            'songs' => [],
            'credits' => [],
            'artists' => [],
            'resolverCalls' => [],
            'songWrites' => 0,
            'tags' => [],
            'lengths' => [],
            'probeFailures' => [],
            'missing' => [],
            'autoFetch' => false,
            'lyricsRequests' => [],
        ];
    }

    public function testAfterAnAlbumTitleEditARescanCreatesNoAlbumAndGapFillsTheAlbumsSongs(): void
    {
        // The album was renamed after its import; its files still carry the old album tag.
        $album = $this->storedAlbum('Ten Thousand Fists (Remastered)');
        $first = $this->storedSong($album, self::DIRECTORY . '/01 Ten Thousand Fists.flac');
        $second = $this->storedSong($album, self::DIRECTORY . '/02 Just Stop.flac');
        $this->tagAll(['album' => 'Ten Thousand Fists', 'artists' => ['Disturbed'], 'albumArtists' => ['Disturbed']]);

        $this->handler()($this->message(self::DIRECTORY));

        self::assertSame([], $this->state->createdAlbums, 'A rescan of known files creates no album.');
        $disturbed = $this->artist('Disturbed');
        self::assertSame([
            $first->getId()->toString() => [[$disturbed, 'primary', 'Disturbed']],
            $second->getId()->toString() => [[$disturbed, 'primary', 'Disturbed']],
        ], $this->state->credits);
        self::assertTrue($this->storedSongById($first)->getAlbumId()->equals($album->getId()));
        self::assertTrue($this->storedSongById($second)->getAlbumId()->equals($album->getId()));
        self::assertSame([$album->getId()->toString() => 'Disturbed'], $this->state->albumCredits, 'The uncredited album is credited from its files.');
    }

    public function testAfterAnAlbumMergeARescanGapFillsTheMovedSongsInTheirNewAlbum(): void
    {
        // The merge moved the bonus disc's songs into the kept album and deleted the bonus album.
        $kept = $this->storedAlbum("Hollywood's Bleeding");
        $directory = "/music/Post Malone/Hollywood's Bleeding (Bonus)";
        $moved = $this->storedSong($kept, $directory . '/01 Circles.flac');
        $this->tagAll(['album' => "Hollywood's Bleeding (Bonus)", 'artists' => ['Post Malone']]);

        $this->handler()($this->message($directory));

        self::assertSame([], $this->state->createdAlbums);
        self::assertSame([$moved->getId()->toString() => [[$this->artist('Post Malone'), 'primary', 'Post Malone']]], $this->state->credits);
        self::assertTrue($this->storedSongById($moved)->getAlbumId()->equals($kept->getId()));
    }

    public function testASongWhoseCreditsWereAllRemovedIsCreditedToItsTaggedArtistAgain(): void
    {
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'), self::DIRECTORY . '/01 Ten Thousand Fists.flac');
        $this->tagAll(['album' => 'Ten Thousand Fists', 'artists' => ['Headhunterz', 'Sound Rush']]);

        $this->handler()($this->message(self::DIRECTORY));

        self::assertSame([$song->getId()->toString() => [[$this->artist('Headhunterz'), 'primary', 'Headhunterz; Sound Rush']]], $this->state->credits);
    }

    #[DataProvider('handMadeCredits')]
    public function testASongWithAnyCreditKeepsItAndGainsNoTagCredit(string $role): void
    {
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'), self::DIRECTORY . '/01 Ten Thousand Fists.flac');
        $handMade = [Uuid::v7()->toString(), $role, 'Someone Else'];
        $this->state->credits[$song->getId()->toString()] = [$handMade];
        $this->tagAll(['album' => 'Ten Thousand Fists', 'artists' => ['Disturbed']]);

        $this->handler()($this->message(self::DIRECTORY));

        self::assertSame([$song->getId()->toString() => [$handMade]], $this->state->credits);
        self::assertSame([], $this->state->artists, 'The tagged artist of a credited song is neither found nor created.');
    }

    /** @return iterable<string, array{string}> */
    public static function handMadeCredits(): iterable
    {
        yield 'a primary credit to another artist' => ['primary'];
        yield 'only a featured credit' => ['featured'];
    }

    public function testAKnownSongsLengthIsMeasuredFromItsAudioAgain(): void
    {
        $path = self::DIRECTORY . '/13 - Amore Infelice.flac';
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'), $path, length: 0.238);
        $this->tagAll(['album' => 'Ten Thousand Fists']);
        $this->state->lengths[$path] = 241.65;

        $this->handler()($this->message(self::DIRECTORY));

        self::assertSame(241.65, $this->storedSongById($song)->getLength());
    }

    public function testAProbeFailureOnAKnownFileKeepsItsLengthAndDoesNotFailTheMessage(): void
    {
        $path = self::DIRECTORY . '/01 Ten Thousand Fists.flac';
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'), $path, length: 213.4);
        $this->tagAll(['album' => 'Ten Thousand Fists', 'artists' => ['Disturbed']]);
        $this->state->probeFailures[$path] = true;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->anything(),
            $this->callback(static fn (array $context): bool => $context['path'] === $path),
        );

        $this->handler($logger)($this->message(self::DIRECTORY));

        $stored = $this->storedSongById($song);
        self::assertSame(213.4, $stored->getLength());
        self::assertSame([$song->getId()->toString() => [[$this->artist('Disturbed'), 'primary', 'Disturbed']]], $this->state->credits, 'The song is still credited.');
    }

    public function testARenamedFileMovesItsSongToTheNewPathAndGapFillsIt(): void
    {
        $oldPath = self::DIRECTORY . '/01 Track.flac';
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'), $oldPath);
        $this->state->missing[$oldPath] = true;
        $newPath = self::DIRECTORY . '/01 Ten Thousand Fists.flac';
        $this->state->tags[$newPath] = ['album' => 'Ten Thousand Fists', 'artists' => ['Disturbed']];

        $this->handler()(new FilesDiscovered($this->libraryId, 'music', self::DIRECTORY, [
            $this->observedFile(new DiscoveredFile($newPath, ltrim($newPath, '/'), 'flac', 1_000_000, 1_700_000_000, $song->getHash())),
        ]));

        self::assertSame($newPath, $this->storedSongById($song)->getPath());
        self::assertSame([$song->getId()->toString() => [[$this->artist('Disturbed'), 'primary', 'Disturbed']]], $this->state->credits);
    }

    public function testANewFileAndAnUncreditedKnownFileResolveTheirArtistsInOneCall(): void
    {
        $album = $this->storedAlbum('Compilation');
        $known = $this->storedSong($album, '/music/Compilation/02 Amore Infelice.flac');
        $newPath = '/music/Compilation/01 Bulldogg.flac';
        $this->state->tags[$known->getPath()] = ['album' => 'Compilation', 'artists' => ['Suspekt']];
        $this->state->tags[$newPath] = ['album' => 'Compilation', 'artists' => ['Bulldogg']];

        $this->handler()(new FilesDiscovered($this->libraryId, 'music', '/music/Compilation', [
            $this->observedFile(new DiscoveredFile($newPath, ltrim($newPath, '/'), 'flac', 1_000_000, 1_700_000_000, 'hash-new')),
            $this->observedFile(new DiscoveredFile($known->getPath(), ltrim($known->getPath(), '/'), 'flac', 1_000_000, 1_700_000_000, $known->getHash())),
        ]));

        self::assertSame([[['Bulldogg', true], ['Suspekt', true]]], $this->state->resolverCalls);
        self::assertSame([], $this->state->createdAlbums, 'The new song joins the album its tag names.');
        $new = array_find($this->state->songs, static fn (Song $song): bool => $song->getPath() === $newPath);
        self::assertNotNull($new);
        self::assertTrue($new->getAlbumId()->equals($album->getId()));
        self::assertSame([
            $new->getId()->toString() => [[$this->artist('Bulldogg'), 'primary', 'Bulldogg']],
            $known->getId()->toString() => [[$this->artist('Suspekt'), 'primary', 'Suspekt']],
        ], $this->state->credits);
    }

    #[DataProvider('automaticFetch')]
    public function testARepairedSongWithoutLyricsRequestsAFetchOnlyWhileAutomaticFetchIsOn(bool $autoFetch): void
    {
        $credited = $this->storedSong($this->storedAlbum('Ten Thousand Fists'), self::DIRECTORY . '/01 Ten Thousand Fists.flac');
        $measured = $this->storedSong($this->state->albums[array_key_first($this->state->albums)], self::DIRECTORY . '/02 Just Stop.flac', length: 0.238);
        $this->state->credits[$measured->getId()->toString()] = [[Uuid::v7()->toString(), 'primary', 'Disturbed']];
        $this->state->tags[$credited->getPath()] = ['album' => 'Ten Thousand Fists', 'artists' => ['Disturbed']];
        $this->state->tags[$measured->getPath()] = ['album' => 'Ten Thousand Fists', 'artists' => ['Disturbed']];
        $this->state->lengths[$measured->getPath()] = 210.0;
        $this->state->autoFetch = $autoFetch;

        $this->handler()($this->message(self::DIRECTORY));

        self::assertSame(
            $autoFetch ? [$credited->getId()->toString(), $measured->getId()->toString()] : [],
            $this->state->lyricsRequests,
            'A song that gains a primary credit or a length is fetched for.',
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function automaticFetch(): iterable
    {
        yield 'automatic fetch on' => [true];
        yield 'automatic fetch off' => [false];
    }

    public function testASongThatHasLyricsRequestsNoFetch(): void
    {
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'), self::DIRECTORY . '/01 Ten Thousand Fists.flac', lyrics: "[00:01.00]Line\n");
        $this->tagAll(['album' => 'Ten Thousand Fists', 'artists' => ['Disturbed']]);
        $this->state->autoFetch = true;

        $this->handler()($this->message(self::DIRECTORY));

        self::assertArrayHasKey($song->getId()->toString(), $this->state->credits);
        self::assertSame([], $this->state->lyricsRequests);
    }

    public function testARetryOfACommittedMessageChangesNothingFurther(): void
    {
        $path = self::DIRECTORY . '/01 Ten Thousand Fists.flac';
        $song = $this->storedSong($this->storedAlbum('Ten Thousand Fists'), $path, length: 0.238);
        $this->tagAll(['album' => 'Ten Thousand Fists', 'artists' => ['Disturbed'], 'albumArtists' => ['Disturbed']]);
        $this->state->lengths[$path] = 241.65;
        $this->state->autoFetch = true;
        $handler = $this->handler();
        $message = $this->message(self::DIRECTORY);

        $handler($message);
        $afterFirst = [
            'credits' => $this->state->credits,
            'albumCredits' => $this->state->albumCredits,
            'length' => $this->storedSongById($song)->getLength(),
            'songWrites' => $this->state->songWrites,
            'resolverCalls' => $this->state->resolverCalls,
            'lyricsRequests' => $this->state->lyricsRequests,
        ];
        self::assertSame([$song->getId()->toString()], $afterFirst['lyricsRequests']);

        $handler($message);

        self::assertSame($afterFirst, [
            'credits' => $this->state->credits,
            'albumCredits' => $this->state->albumCredits,
            'length' => $this->storedSongById($song)->getLength(),
            'songWrites' => $this->state->songWrites,
            'resolverCalls' => $this->state->resolverCalls,
            'lyricsRequests' => $this->state->lyricsRequests,
        ]);
    }

    private function handler(?LoggerInterface $logger = null): FilesDiscoveredHandler
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
        $albums->method('getArtistNamesForAlbum')->willReturnCallback(static fn (Uuid $id): array => isset($state->albumCredits[$id->toString()])
            ? [['name' => $state->albumCredits[$id->toString()], 'role' => 'primary']]
            : []);
        $albums->method('creditArtistIfUncredited')->willReturnCallback(static function (Uuid $id, ?ArtistIdentity $albumArtist = null, ?string $creditedName = null) use ($state): void {
            if ($albumArtist !== null && !isset($state->albumCredits[$id->toString()])) {
                $state->albumCredits[$id->toString()] = $creditedName ?? $albumArtist->name;
            }
        });

        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByHash')->willReturnCallback(static function (string $hash) use ($state): ?Song {
            $song = array_find($state->songs, static fn (Song $song): bool => $song->getHash() === $hash);

            return $song === null ? null : Song::reconstitute(clone $song->getState());
        });
        $songs->method('findFreshByUuid')->willReturnCallback(static function (Uuid $id) use ($state, $transaction): ?Song {
            self::assertTrue($transaction->active, 'A known song is read again inside the transaction that repairs it.');
            $song = $state->songs[$id->toString()] ?? null;

            return $song === null ? null : Song::reconstitute(clone $song->getState());
        });
        $songs->method('findByAlbumSortedByTrack')->willReturnCallback(static fn (Uuid $albumId): array => array_values(array_filter(
            $state->songs,
            static fn (Song $song): bool => $song->getAlbumId()->equals($albumId),
        )));
        $songs->method('persist')->willReturnCallback(static function (Song $song) use ($state): void {
            $state->songs[$song->getId()->toString()] = Song::reconstitute(clone $song->getState());
            $state->songWrites++;
        });
        $songs->method('findCreditedSongIds')->willReturnCallback(static function (array $songIds) use ($state, $transaction): array {
            self::assertTrue($transaction->active, 'Existing credits are read inside the transaction that adds one.');

            return array_values(array_filter(
                array_map(static fn (Uuid $id): string => $id->toString(), $songIds),
                static fn (string $id): bool => ($state->credits[$id] ?? []) !== [],
            ));
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
        $resolver->method('resolve')->willReturnCallback(static function (array $identities) use ($state, $transaction): array {
            $state->resolverCalls[] = array_map(static fn (ArtistIdentity $identity): array => [$identity->name, $transaction->active], $identities);
            $resolved = [];
            foreach ($identities as $identity) {
                $resolved[$identity->key()] = $state->artists[$identity->name] ??= Uuid::v7();
            }

            return $resolved;
        });

        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadata->method('readMetadata')->willReturnCallback(static function (string $path) use ($state, $transaction): ExtractedMetadata {
            self::assertFalse($transaction->active, 'Tags are read outside any transaction.');
            $tags = $state->tags[$path] ?? [];

            return (new ExtractedMetadata())
                ->setTitle(pathinfo($path, PATHINFO_FILENAME))
                ->setAlbum($tags['album'] ?? null)
                ->setArtists($tags['artists'] ?? [])
                ->setAlbumArtists($tags['albumArtists'] ?? []);
        });

        $probe = $this->createStub(AudioProbePortInterface::class);
        $probe->method('probeAudio')->willReturnCallback(static function (string $path) use ($state, $transaction): AudioProbeResult {
            self::assertFalse($transaction->active, 'Audio is probed outside any transaction.');
            if (isset($state->probeFailures[$path])) {
                throw new RuntimeException('ffprobe exited with code 1');
            }

            return new AudioProbeResult('flac', 0, 'flac', 2, 44100, 320000, $state->lengths[$path] ?? null);
        });

        $media = $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class));
        $media->method('inspect')->willReturnCallback(static fn (Uuid $libraryId, array $paths): LibraryMediaFileInspection => new LibraryMediaFileInspection(
            $libraryId,
            '/music',
            array_map(static fn (string $path): LibraryMediaFileCheck => new LibraryMediaFileCheck(
                $path,
                isset($state->missing[$path]) ? LibraryMediaFileVerdict::Missing : LibraryMediaFileVerdict::Deletable,
            ), $paths),
            false,
            true,
        ));

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
            $logger ?? new NullLogger(),
            $media,
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

    /** A song an earlier scan stored, with every technical field its probe gave and no artist credit. */
    private function storedSong(Album $album, string $path, ?float $length = null, ?string $lyrics = null): Song
    {
        $song = Song::create(
            album: $album->getId(),
            title: pathinfo($path, PATHINFO_FILENAME),
            path: $path,
            size: 1_000_000,
            mimeType: 'audio/flac',
            length: $length,
            lyrics: $lyrics,
            hash: 'hash-' . basename($path),
            bitrate: 320000,
            sampleRate: 44100,
            channels: 2,
            codec: 'flac',
            containerFormat: 'flac',
            streamIndex: 0,
        );
        $this->state->songs[$song->getId()->toString()] = Song::reconstitute(clone $song->getState());

        return $song;
    }

    private function storedSongById(Song $song): Song
    {
        $stored = $this->state->songs[$song->getId()->toString()] ?? null;
        self::assertNotNull($stored);

        return $stored;
    }

    /** @param array{album?: string, artists?: list<string>, albumArtists?: list<string>} $tags the tags every stored song's file carries */
    private function tagAll(array $tags): void
    {
        foreach ($this->state->songs as $song) {
            $this->state->tags[$song->getPath()] = $tags;
        }
    }

    /** A rescan of the directory: every stored song's file under it, unchanged. */
    private function message(string $directory): FilesDiscovered
    {
        $files = [];
        foreach ($this->state->songs as $song) {
            if (dirname($song->getPath()) === $directory) {
                $files[] = $this->observedFile(new DiscoveredFile($song->getPath(), ltrim($song->getPath(), '/'), 'flac', 1_000_000, 1_700_000_000, (string) $song->getHash()));
            }
        }

        return new FilesDiscovered($this->libraryId, 'music', $directory, $files);
    }

    /** The artist the fake resolver found or created for the name. */
    private function artist(string $name): string
    {
        self::assertArrayHasKey($name, $this->state->artists, sprintf('No artist was resolved for "%s".', $name));

        return $this->state->artists[$name]->toString();
    }
}
