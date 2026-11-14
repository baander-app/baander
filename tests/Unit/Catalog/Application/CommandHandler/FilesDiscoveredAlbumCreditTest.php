<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\ArtistIdentityResolverInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Infrastructure\AlbumService;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\DiscoveredFileObservations;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * After an import message's songs are committed, an album without an artist credit is credited
 * from the first album-artist tag among the message's files, else among the files of the album's
 * stored songs, else to the artist every one of its songs is credited to. The handler runs with the
 * real album service, over fakes that keep the songs, credits, artists and file tags in memory.
 */
final class FilesDiscoveredAlbumCreditTest extends TestCase
{
    use DiscoveredFileObservations;

    private const string POST_MALONE_ID = 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19';
    private const string HEADHUNTERZ_ID = '7e8ee8b1-5a3b-4e64-9a0c-1d2e3f4a5b6c';

    private \stdClass $state;
    private PassThroughTransaction $transaction;

    protected function setUp(): void
    {
        $this->transaction = new PassThroughTransaction();
        $this->state = (object) [
            'album' => null,
            'songs' => [],
            'songCredits' => [],
            'artists' => [],
            'artistNames' => [],
            'albumCredits' => [],
            'pendingAlbumCredits' => [],
            'tags' => [],
            'reads' => [],
            'persistFailures' => [],
            'heldOnWrite' => false,
            'holdChecks' => 0,
            'events' => [],
        ];
    }

    public function testAnAlbumWithoutAnAlbumArtistTagIsCreditedToTheArtistAllItsTracksShare(): void
    {
        $this->handler()($this->message('/music/Disturbed/Ten Thousand Fists', [
            '01 Ten Thousand Fists' => ['artists' => ['Disturbed']],
            '02 Just Stop' => ['artists' => ['Disturbed']],
            '03 Guarded' => ['artists' => ['Disturbed']],
        ]));

        self::assertSame([[$this->artist('Disturbed'), 'primary', 'Disturbed']], $this->state->albumCredits);
    }

    public function testAnAlbumWhoseTracksHaveDifferentArtistsGetsNoCredit(): void
    {
        $this->handler()($this->message('/music/Compilation', [
            '01 Bulldogg' => ['artists' => ['Bulldogg']],
            '02 Amore Infelice' => ['artists' => ['Suspekt']],
        ]));

        self::assertCount(2, $this->state->songCredits, 'Each song is still credited to its own artist.');
        self::assertSame([], $this->state->albumCredits);
    }

    public function testAnAlbumWithATrackWithoutAnArtistGetsNoSharedArtistCredit(): void
    {
        $this->handler()($this->message('/music/Disturbed/Ten Thousand Fists', [
            '01 Ten Thousand Fists' => ['artists' => ['Disturbed']],
            '02 Untagged' => [],
        ]));

        self::assertSame([], $this->state->albumCredits);
    }

    /**
     * @param list<string> $spellings the artist value of each track, all with the artist's MusicBrainz ID
     */
    #[DataProvider('spellingsOfOneArtist')]
    public function testTracksOfOneArtistCreditTheAlbumUnderTheNameTheyShareElseTheArtistsOwnName(array $spellings, string $creditedName): void
    {
        $this->existingArtist('Headhunterz', self::HEADHUNTERZ_ID);
        $files = [];
        foreach ($spellings as $index => $spelling) {
            $files[sprintf('%02d Track', $index + 1)] = ['artists' => [$spelling], 'ids' => [self::HEADHUNTERZ_ID]];
        }

        $this->handler()($this->message('/music/Headhunterz/Studio', $files));

        self::assertSame([[$this->artist(self::HEADHUNTERZ_ID), 'primary', $creditedName]], $this->state->albumCredits);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function spellingsOfOneArtist(): iterable
    {
        yield 'every track under one alias' => [['HeadHunterz', 'HeadHunterz'], 'HeadHunterz'];
        yield 'tracks under two aliases' => [['HeadHunterz', 'HEADHUNTERZ'], 'Headhunterz'];
    }

    public function testTheFirstDiscsAlbumArtistTagCreditsTheAlbumAndTheSecondDiscAddsNoCredit(): void
    {
        $albumArtist = ['albumArtists' => ['Post Malone'], 'albumArtistIds' => [self::POST_MALONE_ID]];
        $handler = $this->handler();

        $handler($this->message('/music/Post Malone/CD1', [
            '01 Hollywood\'s Bleeding' => ['artists' => ['Post Malone'], ...$albumArtist],
            '02 Saint-Tropez' => ['artists' => ['Post Malone, Hank Williams Jr.'], ...$albumArtist],
        ], "Hollywood's Bleeding"));
        $handler($this->message('/music/Post Malone/CD2', [
            '01 Circles' => ['artists' => ['Post Malone'], 'albumArtists' => ['Post Malone feat. Others']],
        ], "Hollywood's Bleeding"));

        self::assertSame([[$this->artist(self::POST_MALONE_ID), 'primary', 'Post Malone']], $this->state->albumCredits);
    }

    public function testASecondDiscWithoutAnAlbumArtistTagIsCreditedFromTheFirstDiscsStoredFiles(): void
    {
        $this->existingAlbum("Hollywood's Bleeding");
        $albumArtist = ['albumArtists' => ['Post Malone'], 'albumArtistIds' => [self::POST_MALONE_ID]];
        $this->storedSong('/music/Post Malone/CD1/01 Hollywood\'s Bleeding.flac', 'Post Malone', ['artists' => ['Post Malone'], ...$albumArtist]);
        $this->storedSong('/music/Post Malone/CD1/02 Saint-Tropez.flac', 'Post Malone, Hank Williams Jr.', ['artists' => ['Post Malone, Hank Williams Jr.'], ...$albumArtist]);

        $this->handler()($this->message('/music/Post Malone/CD2', [
            '01 Circles' => ['artists' => ['Post Malone']],
            '02 Take What You Want' => ['artists' => ['Post Malone, Ozzy Osbourne']],
        ], "Hollywood's Bleeding"));

        self::assertSame([[$this->artist(self::POST_MALONE_ID), 'primary', 'Post Malone']], $this->state->albumCredits);
    }

    public function testTheFirstAlbumArtistTagInPathOrderCreditsItsFirstValuesArtistUnderEveryValueJoined(): void
    {
        // The message lists its files out of path order.
        $this->handler()($this->message('/music/Headhunterz/Singles', [
            '02 Rescue Me' => ['artists' => ['Headhunterz'], 'albumArtists' => ['Sound Rush']],
            '01 Dragonborn' => ['artists' => ['Headhunterz'], 'albumArtists' => ['Headhunterz', 'Sound Rush']],
        ]));

        self::assertSame([[$this->artist('Headhunterz'), 'primary', 'Headhunterz; Sound Rush']], $this->state->albumCredits);
    }

    /** @param array{albumArtists?: list<string>} $messageTag */
    #[DataProvider('albumArtistTagsOfTheMessage')]
    public function testAnAlbumWithAHandMadeCreditKeepsItAndGainsNone(array $messageTag): void
    {
        $this->existingAlbum('Ten Thousand Fists');
        $handMade = [$this->existingArtist('Someone Else', null)->toString(), 'featured', 'Someone Else'];
        $this->state->albumCredits = [$handMade];
        $stored = '/music/Disturbed/Ten Thousand Fists/01 Ten Thousand Fists.flac';
        $this->storedSong($stored, 'Disturbed', ['artists' => ['Disturbed'], 'albumArtists' => ['Disturbed']]);

        $this->handler()($this->message('/music/Disturbed/Ten Thousand Fists', [
            '02 Just Stop' => ['artists' => ['Disturbed'], ...$messageTag],
        ]));

        self::assertSame([$handMade], $this->state->albumCredits);
        self::assertNotContains($stored, $this->state->reads, 'A credited album reads none of its stored files.');
    }

    /** @return iterable<string, array{array{albumArtists?: list<string>}}> */
    public static function albumArtistTagsOfTheMessage(): iterable
    {
        yield 'a message with an album-artist tag' => [['albumArtists' => ['Disturbed']]];
        yield 'a message without one' => [[]];
    }

    public function testAMessageWithAFailingFileStillDecidesTheAlbumCreditBeforeRethrowing(): void
    {
        $this->state->persistFailures['hash-01 Fails'] = new RuntimeException('Song write failed');

        try {
            $this->handler()($this->message('/music/Disturbed/Ten Thousand Fists', [
                '01 Fails' => ['artists' => ['Disturbed']],
                '02 Just Stop' => ['artists' => ['Disturbed']],
            ]));
            self::fail('The failed file must be reported.');
        } catch (RuntimeException $failure) {
            self::assertStringStartsWith('Failed to process 1 file(s)', $failure->getMessage());
        }

        self::assertSame([[$this->artist('Disturbed'), 'primary', 'Disturbed']], $this->state->albumCredits);
    }

    public function testAnImportHeldByADeleteDecidesTheAlbumCreditBeforeItRequeues(): void
    {
        $this->existingAlbum('Ten Thousand Fists');
        $disturbed = $this->existingArtist('Disturbed', null);
        $this->storedSong('/music/Disturbed/Ten Thousand Fists/01 Ten Thousand Fists.flac', 'Disturbed', ['artists' => ['Disturbed']], $disturbed);
        $this->state->heldOnWrite = true;

        $this->handler()($this->message('/music/Disturbed/Ten Thousand Fists', [
            '02 Just Stop' => ['artists' => ['Disturbed']],
        ]));

        self::assertSame([[$disturbed->toString(), 'primary', 'Disturbed']], $this->state->albumCredits);
        self::assertSame(
            ['album credited', 'import requeued'],
            array_values(array_intersect($this->state->events, ['album credited', 'import requeued'])),
        );
    }

    public function testAnAlbumWithoutSongsGetsNoCreditFromItsTag(): void
    {
        $this->state->persistFailures['hash-01 Fails'] = new RuntimeException('Song write failed');

        try {
            $this->handler()($this->message('/music/Disturbed/Ten Thousand Fists', [
                '01 Fails' => ['artists' => ['Disturbed'], 'albumArtists' => ['Disturbed']],
            ]));
            self::fail('The failed file must be reported.');
        } catch (RuntimeException) {
        }

        self::assertNotNull($this->state->album);
        self::assertSame([], $this->state->albumCredits);
    }

    private function handler(): FilesDiscoveredHandler
    {
        $state = $this->state;
        $transaction = $this->transaction;

        $resolver = $this->createStub(ArtistIdentityResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (array $identities) use ($state, $transaction): array {
            self::assertTrue($transaction->active, 'Artists resolve inside the transaction that writes their credits.');
            $resolved = [];
            foreach ($identities as $identity) {
                // As the real resolver does, an ID resolves to one artist whatever name it is tagged with.
                $key = $identity->musicbrainzId?->toString() ?? $identity->name;
                if (!isset($state->artists[$key])) {
                    $state->artists[$key] = Uuid::v7();
                    $state->artistNames[$state->artists[$key]->toString()] = $identity->name;
                }
                $resolved[$identity->key()] = $state->artists[$key];
            }

            return $resolved;
        });

        $albums = $this->createStub(AlbumRepositoryInterface::class);
        $albums->method('findByTitleAndLibrary')->willReturnCallback(
            static fn (string $title): ?Album => $state->album?->getTitle() === $title ? $state->album : null,
        );
        $albums->method('persist')->willReturnCallback(static function (Album $album) use ($state): void {
            $album->setCoverImage(Uuid::v7());
            $state->album = $album;
        });
        $albums->method('flush')->willReturnCallback(static function () use ($state): void {
            array_push($state->albumCredits, ...$state->pendingAlbumCredits);
            $state->pendingAlbumCredits = [];
        });
        $albums->method('getArtistNamesForAlbum')->willReturnCallback(static fn (): array => array_map(
            static fn (array $credit): array => ['name' => $credit[2], 'role' => $credit[1]],
            $state->albumCredits,
        ));
        $albums->method('lockForArtistCredit')->willReturnCallback(static function () use ($state, $transaction): ?array {
            self::assertTrue($transaction->active, 'The album is locked inside the transaction that credits it.');
            if ($state->albumCredits !== []) {
                return null;
            }
            $credits = [];
            foreach ($state->songs as $song) {
                $songId = $song->getId()->toString();
                $credits[$songId] = [];
                foreach ($state->songCredits[$songId] ?? [] as [$artistId, $role, $creditedName]) {
                    if ($role === 'primary') {
                        $credits[$songId][] = ['artistId' => $artistId, 'artistName' => $state->artistNames[$artistId->toString()], 'creditedName' => $creditedName];
                    }
                }
            }

            return $credits;
        });
        $albums->method('addArtistCredit')->willReturnCallback(static function (Uuid $albumId, Uuid $artistId, string $role, string $creditedName) use ($state): void {
            self::assertTrue($albumId->equals($state->album->getId()));
            $state->pendingAlbumCredits[] = [$artistId->toString(), $role, $creditedName];
            $state->events[] = 'album credited';
        });

        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('persist')->willReturnCallback(static function (Song $song) use ($state): void {
            $failure = $state->persistFailures[$song->getHash()] ?? null;
            if ($failure !== null) {
                throw $failure;
            }
            $state->songs[] = $song;
        });
        $songs->method('addArtistCredit')->willReturnCallback(static function (Uuid $songId, Uuid $artistId, string $role, string $creditedName) use ($state): void {
            $state->songCredits[$songId->toString()][] = [$artistId, $role, $creditedName];
        });
        $songs->method('findByAlbumSortedByTrack')->willReturnCallback(static fn (): array => $state->songs);

        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadata->method('readMetadata')->willReturnCallback(static function (string $path) use ($state): ExtractedMetadata {
            $state->reads[] = $path;
            $tags = $state->tags[$path] ?? [];

            return (new ExtractedMetadata())
                ->setTitle(pathinfo($path, PATHINFO_FILENAME))
                ->setAlbum($tags['album'] ?? null)
                ->setArtists($tags['artists'] ?? [])
                ->setMbArtistIds($tags['ids'] ?? [])
                ->setAlbumArtists($tags['albumArtists'] ?? [])
                ->setMbAlbumArtistIds($tags['albumArtistIds'] ?? []);
        });

        $media = $this->createStub(LibraryMediaFilesInterface::class);
        // The album is resolved in the first transaction; with heldOnWrite, a delete claims the
        // library before the songs' transaction.
        $media->method('isHeldByDeleteForImport')->willReturnCallback(
            static fn (): bool => $state->heldOnWrite && $state->holdChecks++ > 0,
        );

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use ($state): Envelope {
            if ($message instanceof FilesDiscovered) {
                $state->events[] = 'import requeued';
            }

            return new Envelope($message);
        });

        $albumService = new AlbumService(
            $albums,
            new CoverImageDiscarder($this->createStub(ImagePortInterface::class), $this->createStub(StoragePortInterface::class), new NullLogger()),
            $transaction,
            $resolver,
        );

        return new FilesDiscoveredHandler(
            $albumService,
            $this->createStub(GenrePortInterface::class),
            $songs,
            $this->createStub(MoviePortInterface::class),
            $this->createStub(VideoRepositoryInterface::class),
            $metadata,
            new FFprobeAdapter(new JsonEncoder()),
            $bus,
            $this->createStub(LyricsFetchRequestInterface::class),
            $this->createStub(AlbumMetadataSyncRequestInterface::class),
            new NullLogger(),
            $this->observedMediaFiles($media),
            $transaction,
            audioProbe: $this->audioProbeStub(),
            artistIdentities: $resolver,
        );
    }

    /**
     * @param array<string, array{artists?: list<string>, ids?: list<string>, albumArtists?: list<string>, albumArtistIds?: list<string>}> $files the tags of each file, by file name, in message order
     * @param string|null                                                                                                                  $album the album tag of every file; the directory's name when null
     */
    private function message(string $directory, array $files, ?string $album = null): FilesDiscovered
    {
        $album ??= basename($directory);
        $discovered = [];
        foreach ($files as $name => $tags) {
            $path = $directory . '/' . $name . '.flac';
            $this->state->tags[$path] = ['album' => $album, ...$tags];
            $discovered[] = $this->observedFile(new DiscoveredFile($path, ltrim($path, '/'), 'flac', 1_000_000, 1_700_000_000, 'hash-' . $name));
        }

        return new FilesDiscovered(Uuid::v7(), 'music', $directory, $discovered);
    }

    private function existingAlbum(string $title): void
    {
        $album = Album::create(Uuid::v7(), $title, 'Studio');
        $album->setCoverImage(Uuid::v7());
        $this->state->album = $album;
    }

    private function existingArtist(string $name, ?string $musicbrainzId): Uuid
    {
        $id = Uuid::v7();
        $this->state->artists[$musicbrainzId ?? $name] = $id;
        $this->state->artistNames[$id->toString()] = $name;

        return $id;
    }

    /**
     * A song an earlier import stored in the album, with a primary credit under $creditedName.
     *
     * @param array{artists?: list<string>, ids?: list<string>, albumArtists?: list<string>, albumArtistIds?: list<string>} $tags the tags its file carries
     */
    private function storedSong(string $path, string $creditedName, array $tags, ?Uuid $artist = null): void
    {
        $song = Song::create(album: $this->state->album->getId(), title: pathinfo($path, PATHINFO_FILENAME), path: $path, size: 1_000_000, mimeType: 'audio/flac', hash: 'stored-' . $path);
        $artist ??= $this->state->artists[$creditedName] ?? $this->existingArtist($creditedName, null);
        $this->state->songs[] = $song;
        $this->state->songCredits[$song->getId()->toString()][] = [$artist, 'primary', $creditedName];
        $this->state->tags[$path] = $tags;
    }

    /** The artist the fake resolver gave an ID, or a name without an ID. */
    private function artist(string $musicbrainzIdOrName): string
    {
        $artist = $this->state->artists[$musicbrainzIdOrName] ?? null;
        self::assertNotNull($artist, sprintf('No artist was resolved for "%s".', $musicbrainzIdOrName));

        return $artist->toString();
    }
}
