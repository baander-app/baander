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
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
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
 * A batch resolves the artists of all its new songs in one call inside its transaction, before it
 * writes any song or genre, and credits each song to the artist resolved for its own tags under the
 * name it was tagged with. A file that names its artists one by one, with one MusicBrainz ID each,
 * also credits every artist after the first as featured.
 */
final class FilesDiscoveredArtistCreditTest extends TestCase
{
    use DiscoveredFileObservations;

    private const string DIRECTORY = '/music/Credit Album';
    private const string HEADHUNTERZ_ID = '7e8ee8b1-5a3b-4e64-9a0c-1d2e3f4a5b6c';
    private const string KREWELLA_ID = '2c4e6a8b-0d1f-4a3c-8e5b-7d9f1a2c3e4b';
    private const string POST_MALONE_ID = '5b7d9f1a-3c5e-4b7d-9f1a-3c5e7b9d1f3a';
    private const string LUKE_COMBS_ID = '8a0c2e4f-6b8d-4f0a-a2c4-6e8a0c2e4f6b';

    private \stdClass $state;
    private PassThroughTransaction $transaction;

    protected function setUp(): void
    {
        $this->state = (object) [
            'events' => [],
            'resolved' => [],
            'artists' => [],
            'titles' => [],
            'persisted' => [],
            'credits' => [],
            'persistFailures' => [],
            'flushes' => 0,
        ];
        $this->transaction = new PassThroughTransaction();
    }

    public function testEveryFileOfOneArtistIsCreditedToTheArtistResolvedOnceForTheBatch(): void
    {
        $names = array_map(static fn (int $track): string => sprintf('%02d Track', $track), range(1, 14));
        $tags = array_fill_keys($names, ['artists' => ['Disturbed'], 'genres' => ['Metal']]);

        $this->handler($tags)($this->message(...$names));

        self::assertSame(array_fill(0, 14, ['Disturbed', null]), $this->resolvedIdentities());

        $perSong = ['song persisted', 'artist credited', 'genre linked'];
        self::assertSame(['artists resolved', 'genres resolved', ...array_merge(...array_fill(0, 14, $perSong))], $this->state->events);

        $artist = $this->state->artists[new ArtistIdentity('Disturbed')->key()]->toString();
        self::assertSame(array_fill_keys($names, [[$artist, 'primary', 'Disturbed']]), $this->creditsBySong());
    }

    public function testSeparateArtistValuesCreditTheFirstValuesArtistUnderEveryValueJoined(): void
    {
        $tags = ['02 - Rescue Me' => ['artists' => ['Headhunterz', 'Sound Rush', 'Eurielle']]];

        $this->handler($tags)($this->message('02 - Rescue Me'));

        self::assertSame([['Headhunterz', null]], $this->resolvedIdentities());
        $artist = $this->state->artists[new ArtistIdentity('Headhunterz')->key()]->toString();
        self::assertSame(['02 - Rescue Me' => [[$artist, 'primary', 'Headhunterz; Sound Rush; Eurielle']]], $this->creditsBySong());
    }

    /** @param list<string> $artists */
    #[DataProvider('singleValues')]
    public function testASingleArtistValueIsCreditedWholeWithoutPaddingOrNulBytes(array $artists, string $name): void
    {
        $this->handler(['01 Song' => ['artists' => $artists]])($this->message('01 Song'));

        self::assertSame([[$name, null]], $this->resolvedIdentities());
        $artist = $this->state->artists[new ArtistIdentity($name)->key()]->toString();
        self::assertSame(['01 Song' => [[$artist, 'primary', $name]]], $this->creditsBySong());
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function singleValues(): iterable
    {
        yield 'a value naming two artists' => [['Post Malone, Hank Williams Jr.'], 'Post Malone, Hank Williams Jr.'];
        yield 'an ID3 value with its terminator and a blank second value' => [[" Bulldogg\0", ' '], 'Bulldogg'];
    }

    public function testTwoSpellingsWithOneMusicBrainzIdAreEachCreditedUnderTheirOwnSpelling(): void
    {
        $tags = [
            '01 Dragonborn' => ['artists' => ['Headhunterz'], 'ids' => [self::HEADHUNTERZ_ID]],
            '02 Orange Heart' => ['artists' => ['HeadHunterz'], 'ids' => [self::HEADHUNTERZ_ID]],
        ];
        // As the real resolver does, an ID resolves to one artist whatever name it is tagged with.
        $byIdElseName = static fn (ArtistIdentity $identity): string => $identity->musicbrainzId?->toString() ?? $identity->name;

        $this->handler($tags, $byIdElseName)($this->message('01 Dragonborn', '02 Orange Heart'));

        self::assertSame(
            [['Headhunterz', self::HEADHUNTERZ_ID], ['HeadHunterz', self::HEADHUNTERZ_ID]],
            $this->resolvedIdentities(),
            'Both spellings reach the resolver in file order, so the first one names a new artist.',
        );
        $artist = $this->state->artists[self::HEADHUNTERZ_ID]->toString();
        self::assertSame([
            '01 Dragonborn' => [[$artist, 'primary', 'Headhunterz']],
            '02 Orange Heart' => [[$artist, 'primary', 'HeadHunterz']],
        ], $this->creditsBySong());
    }

    public function testAFileThatNamesEachArtistWithItsOwnIdCreditsTheFirstAsPrimaryAndTheOthersAsFeatured(): void
    {
        $tags = [
            '01 Guy for That' => [
                'artists' => ['Post Malone feat. Luke Combs'],
                'individualArtists' => ['Post Malone', 'Luke Combs'],
                'ids' => [self::POST_MALONE_ID, self::LUKE_COMBS_ID],
            ],
            '02 Ten Thousand Fists' => ['artists' => ['Disturbed']],
        ];

        $this->handler($tags)($this->message('01 Guy for That', '02 Ten Thousand Fists'));

        self::assertSame(
            [['Post Malone', self::POST_MALONE_ID], ['Luke Combs', self::LUKE_COMBS_ID], ['Disturbed', null]],
            $this->resolvedIdentities(),
            'Every artist of the batch reaches the one resolver call, each by its own ID, in file order.',
        );
        $postMalone = $this->state->artists[new ArtistIdentity('Post Malone', [self::POST_MALONE_ID])->key()]->toString();
        $lukeCombs = $this->state->artists[new ArtistIdentity('Luke Combs', [self::LUKE_COMBS_ID])->key()]->toString();
        $disturbed = $this->state->artists[new ArtistIdentity('Disturbed')->key()]->toString();
        self::assertSame([
            '01 Guy for That' => [[$postMalone, 'primary', 'Post Malone feat. Luke Combs'], [$lukeCombs, 'featured', 'Luke Combs']],
            '02 Ten Thousand Fists' => [[$disturbed, 'primary', 'Disturbed']],
        ], $this->creditsBySong());
    }

    public function testSeparateArtistValuesWithAnIdEachCreditEveryArtistByItsId(): void
    {
        $tags = ['03 Feel Alive' => ['artists' => ['Headhunterz', 'Krewella'], 'ids' => [self::HEADHUNTERZ_ID, self::KREWELLA_ID]]];

        $this->handler($tags)($this->message('03 Feel Alive'));

        self::assertSame([['Headhunterz', self::HEADHUNTERZ_ID], ['Krewella', self::KREWELLA_ID]], $this->resolvedIdentities());
        $headhunterz = $this->state->artists[new ArtistIdentity('Headhunterz', [self::HEADHUNTERZ_ID])->key()]->toString();
        $krewella = $this->state->artists[new ArtistIdentity('Krewella', [self::KREWELLA_ID])->key()]->toString();
        self::assertSame(
            ['03 Feel Alive' => [[$headhunterz, 'primary', 'Headhunterz; Krewella'], [$krewella, 'featured', 'Krewella']]],
            $this->creditsBySong(),
        );
    }

    /**
     * @param array{artists: list<string>, individualArtists?: list<string>, ids: list<string>} $tags
     * @param array{string, string|null}                                                       $identity the name and ID the one primary credit resolves by
     */
    #[DataProvider('namesThatDoNotPairWithTheIds')]
    public function testArtistNamesThatDoNotPairOneToOneWithTheValidIdsKeepASinglePrimaryCredit(array $tags, array $identity, string $creditedName): void
    {
        $this->handler(['04 Song' => $tags])($this->message('04 Song'));

        self::assertSame([$identity], $this->resolvedIdentities());
        $artist = $this->state->artists[new ArtistIdentity($identity[0], $identity[1] === null ? [] : [$identity[1]])->key()]->toString();
        self::assertSame(['04 Song' => [[$artist, 'primary', $creditedName]]], $this->creditsBySong());
    }

    /** @return iterable<string, array{array{artists: list<string>, individualArtists?: list<string>, ids: list<string>}, array{string, string|null}, string}> */
    public static function namesThatDoNotPairWithTheIds(): iterable
    {
        yield 'two artist values with one ID' => [
            ['artists' => ['Headhunterz', 'Sound Rush'], 'ids' => [self::HEADHUNTERZ_ID]],
            ['Headhunterz', self::HEADHUNTERZ_ID],
            'Headhunterz; Sound Rush',
        ];
        yield 'one artist value with two IDs' => [
            ['artists' => ['Headhunterz feat. Krewella'], 'ids' => [self::HEADHUNTERZ_ID, self::KREWELLA_ID]],
            ['Headhunterz feat. Krewella', null],
            'Headhunterz feat. Krewella',
        ];
        yield 'two individual artists with one valid ID and one malformed' => [
            ['artists' => ['Headhunterz feat. Krewella'], 'individualArtists' => ['Headhunterz', 'Krewella'], 'ids' => [self::HEADHUNTERZ_ID, 'krewella']],
            ['Headhunterz feat. Krewella', self::HEADHUNTERZ_ID],
            'Headhunterz feat. Krewella',
        ];
        yield 'two artist values with one ID repeated in another letter case' => [
            ['artists' => ['Headhunterz', 'HeadHunterz'], 'ids' => [self::HEADHUNTERZ_ID, strtoupper(self::HEADHUNTERZ_ID)]],
            ['Headhunterz', self::HEADHUNTERZ_ID],
            'Headhunterz; HeadHunterz',
        ];
    }

    public function testAFileWithoutAnArtistIsImportedWithoutACredit(): void
    {
        $tags = ['01 Named' => ['artists' => ['Suspekt']], '02 Untagged' => [], '03 Blank' => ['artists' => ['', "\0"]]];

        $this->handler($tags)($this->message('01 Named', '02 Untagged', '03 Blank'));

        self::assertSame([['Suspekt', null]], $this->resolvedIdentities());
        self::assertSame(['01 Named', '02 Untagged', '03 Blank'], $this->state->persisted);
        self::assertSame(['01 Named'], array_keys($this->creditsBySong()));
    }

    public function testAFileReplacedSinceItsTagsWereReadResolvesNoArtist(): void
    {
        $tags = ['01 Kept' => ['artists' => ['Disturbed']], '02 Replaced' => ['artists' => ['Headhunterz']]];

        $this->handler($tags, replaced: self::DIRECTORY . '/02 Replaced.flac')($this->message('01 Kept', '02 Replaced'));

        self::assertSame([['Disturbed', null]], $this->resolvedIdentities());
        self::assertSame(['01 Kept'], $this->state->persisted);
        self::assertSame(['01 Kept'], array_keys($this->creditsBySong()));
    }

    public function testABatchThatFailsOneFileStillCommitsTheOthersWithTheirCredits(): void
    {
        $tags = ['01 Fails' => ['artists' => ['Disturbed']], '02 Kept' => ['artists' => ['Disturbed']]];
        $this->state->persistFailures['hash-01 Fails'] = new RuntimeException('Song write failed');

        try {
            $this->handler($tags)($this->message('01 Fails', '02 Kept'));
            self::fail('The failed file must be reported.');
        } catch (RuntimeException $failure) {
            self::assertSame(
                'Failed to process 1 file(s): ' . self::DIRECTORY . '/01 Fails.flac: Song write failed',
                $failure->getMessage(),
            );
        }

        self::assertSame(1, $this->state->flushes);
        self::assertSame(['02 Kept'], $this->state->persisted);
        $artist = $this->state->artists[new ArtistIdentity('Disturbed')->key()]->toString();
        self::assertSame(['02 Kept' => [[$artist, 'primary', 'Disturbed']]], $this->creditsBySong());
    }

    /**
     * @param array<string, array{artists?: list<string>, individualArtists?: list<string>, ids?: list<string>, genres?: list<string>}> $tags     the tags of each file, by file name
     * @param (\Closure(ArtistIdentity): string)|null                                                                                   $artistOf which identities the fake resolver gives one artist; by default, those with one key
     * @param string|null                                                                                                               $replaced a file that changes on disk while its tags are read
     */
    private function handler(array $tags, ?\Closure $artistOf = null, ?string $replaced = null): FilesDiscoveredHandler
    {
        $state = $this->state;
        $transaction = $this->transaction;
        $artistOf ??= static fn (ArtistIdentity $identity): string => $identity->key();

        $album = Album::create(Uuid::v7(), 'Credit Album', 'Studio');
        $album->setCoverImage(Uuid::v7());
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByTitleAndLibrary')->willReturn($album);

        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('persist')->willReturnCallback(static function (Song $song) use ($state): void {
            $failure = $state->persistFailures[$song->getHash()] ?? null;
            if ($failure !== null) {
                throw $failure;
            }
            $state->titles[$song->getId()->toString()] = $song->getTitle();
            $state->persisted[] = $song->getTitle();
            $state->events[] = 'song persisted';
        });
        $songs->method('addArtistCredit')->willReturnCallback(static function (Uuid $songId, Uuid $artistId, string $role, string $creditedName) use ($state): void {
            $state->credits[] = [$songId->toString(), $artistId->toString(), $role, $creditedName];
            $state->events[] = 'artist credited';
        });
        $songs->method('flush')->willReturnCallback(static function () use ($state): void {
            $state->flushes++;
        });

        $resolver = $this->createStub(ArtistIdentityResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (array $identities) use ($state, $transaction, $artistOf): array {
            $state->resolved[] = [$identities, $transaction->active];
            $state->events[] = 'artists resolved';
            $resolved = [];
            foreach ($identities as $identity) {
                $resolved[$identity->key()] = $state->artists[$artistOf($identity)] ??= Uuid::v7();
            }

            return $resolved;
        });

        $genres = $this->createStub(GenrePortInterface::class);
        $genres->method('findOrCreateForImport')->willReturnCallback(static function (array $names) use ($state): array {
            $state->events[] = 'genres resolved';

            return array_fill_keys($names, Uuid::v7());
        });
        $genres->method('addSongToGenre')->willReturnCallback(static function () use ($state): bool {
            $state->events[] = 'genre linked';

            return true;
        });

        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadata->method('readMetadata')->willReturnCallback(function (string $path) use ($tags, $replaced): ExtractedMetadata {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if ($path === $replaced) {
                $this->availableHashes[$path] = 'replaced';
            }

            return (new ExtractedMetadata())
                ->setTitle($name)
                ->setAlbum('Credit Album')
                ->setArtists($tags[$name]['artists'] ?? [])
                ->setIndividualArtists($tags[$name]['individualArtists'] ?? [])
                ->setMbArtistIds($tags[$name]['ids'] ?? [])
                ->setGenre($tags[$name]['genres'] ?? []);
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
            artistIdentities: $resolver,
        );
    }

    private function message(string ...$names): FilesDiscovered
    {
        $files = array_map(
            fn (string $name): DiscoveredFile => $this->observedFile(new DiscoveredFile(
                self::DIRECTORY . '/' . $name . '.flac',
                'Credit Album/' . $name . '.flac',
                'flac',
                1_000_000,
                1_700_000_000,
                'hash-' . $name,
            )),
            $names,
        );

        return new FilesDiscovered(Uuid::v7(), 'music', self::DIRECTORY, $files);
    }

    /** @return list<array{string, string|null}> the name and MusicBrainz ID of each identity the batch's only resolver call was given */
    private function resolvedIdentities(): array
    {
        self::assertCount(1, $this->state->resolved, 'The batch resolves its artists in one call.');
        [$identities, $insideTransaction] = $this->state->resolved[0];
        self::assertTrue($insideTransaction, 'The batch resolves its artists inside its transaction.');

        return array_map(
            static fn (ArtistIdentity $identity): array => [$identity->name, $identity->musicbrainzId?->toString()],
            $identities,
        );
    }

    /** @return array<string, list<array{string, string, string}>> the artist, role and credited name of each credit, by song title */
    private function creditsBySong(): array
    {
        $credits = [];
        foreach ($this->state->credits as [$songId, $artistId, $role, $creditedName]) {
            $credits[$this->state->titles[$songId]][] = [$artistId, $role, $creditedName];
        }

        return $credits;
    }
}
