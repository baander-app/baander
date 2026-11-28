<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\MessageHandler\DrainAlbumMatchesHandler;
use App\Catalog\Application\MessageHandler\QueueArtistSyncsHandler;
use App\Catalog\Application\MessageHandler\RequestAlbumMatchHandler;
use App\Catalog\Application\MessageHandler\SyncArtistHandler;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticAlbumMatcherInterface;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticMatchOutcome;
use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCoverImage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCreditedArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzLabel;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMedium;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMediumSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseGroup;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzTrack;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Application\MessageHandler\SyncLibraryHandler;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Metadata\Application\Message\SyncArtistsMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\MetadataSyncOrchestrator;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Support\FinalSchemaBootstrap;
use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Port\AudioProbePortInterface;
use App\Transcode\Application\Port\VideoProbePortInterface;
use Baander\MediaMetadata\Model\ExtractedMetadata;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Runs a real scan through the console, ingests what it discovered, and follows the album match
 * queue from the request to the match, and an artist fill from the import that queued it to the
 * admin sync that then leaves the artist alone, with the switch in PostgreSQL. MusicBrainz is a fake
 * that answers from canned releases and artists.
 */
final class NewAlbumMetadataSyncTest extends KernelTestCase
{
    private const string POST_MALONE = 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19';
    private const string TIM_MCGRAW = '6a8eb12c-3ce7-4b5d-a2c5-1d1b6c6c9a01';
    private const string HANK_WILLIAMS_JR = '7b1e5a92-7d1f-4a35-8f71-3e2f8d3b6a02';
    private const string EXPLICIT_EDITION = '1a0e7a4c-0e1f-4f9b-9f4c-6b2c5d3e8a01';
    private const string CLEAN_EDITION = '2b1f8b5d-1f2a-4a0c-8a5d-7c3d6e4f9b02';
    private const string RELEASE_GROUP = '3c2a9c6e-2a3b-4b1d-9b6e-8d4e7f5a0c03';
    private const string WRONG_ONES = '4d3b0d7f-3b4c-4c2e-8c7f-9e5f8a6b1d04';
    private const string FINER_THINGS = '5e4c1e8a-4c5d-4d3f-9d8a-0f6a9b7c2e05';
    private const string YOURS_EXPLICIT = '6f5d2f9b-5d6e-4e4a-8e9b-1a7b0c8d3f06';
    private const string YOURS_CLEAN = '7a6e3a0c-6e7f-4f5b-9f0c-2b8c1d9e4a07';
    private const string NEVER_LOVE_YOU_AGAIN = '8b7f4b1d-7f8a-4a6c-8a1d-3c9d2e0f5b08';
    private const string HEADHUNTERZ = '9c8a5c2e-8a9b-4b7d-9b2e-4d0e3f1a6c09';

    private string $libraryDirectory;
    private ?string $schema = null;
    private string $searchPath;

    protected function setUp(): void
    {
        $this->libraryDirectory = sys_get_temp_dir() . '/baander-album-sync-' . (random_bytes(4) |> bin2hex(...));
        mkdir($this->libraryDirectory . '/Album', 0o777, true);
        file_put_contents($this->libraryDirectory . '/Album/track.flac', 'not really audio');
        self::bootKernel();
        $this->isolateSchema();
        $probe = $this->createStubForIntersectionOfInterfaces([AudioProbePortInterface::class, VideoProbePortInterface::class]);
        $probe->method('probeAudio')->willReturn(new AudioProbeResult('flac', 0, 'flac'));
        static::getContainer()->set(AudioProbePortInterface::class, $probe);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->delete(MetadataSettingDefinitions::AUTO_SYNC);
        new Filesystem()->remove($this->libraryDirectory);
        if ($this->schema !== null) {
            $connection = static::getContainer()->get(Connection::class);
            $connection->executeStatement('SET search_path TO ' . $this->searchPath);
            $connection->executeStatement('DROP SCHEMA ' . $connection->quoteIdentifier($this->schema) . ' CASCADE');
        }
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testIngestRequestsAMatchOfTheAlbumAScanCreatedOnceTheSettlingDelayPasses(): void
    {
        $this->setAutomaticMatching(true);
        $library = $this->createLibrary();

        $this->scanAndIngest($library);

        $album = $this->onlyAlbum($library);
        $state = $this->states()->findByAlbum($album);
        self::assertSame(AlbumMatchStatus::Requested, $state?->status);
        self::assertSame(AlbumMatchSource::Ingest, $state->source);
        self::assertGreaterThan(new DateTimeImmutable('+4 minutes'), $state->dueAt);
        self::assertSame([300_000], $this->queuedRuns());
    }

    public function testARedeliveredDiscoveryKeepsOneRequestAndOneQueuedRun(): void
    {
        $this->setAutomaticMatching(true);
        $library = $this->createLibrary();

        $discoveries = $this->scanAndIngest($library);
        self::assertNotSame([], $discoveries);
        $ingest = static::getContainer()->get(FilesDiscoveredHandler::class);
        foreach ($discoveries as $discovery) {
            $ingest($discovery);
        }

        self::assertNotNull($this->states()->findByAlbum($this->onlyAlbum($library)));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM album_match_states'));
        self::assertSame([300_000], $this->queuedRuns());
    }

    public function testWhileAutomaticMatchingIsOffIngestRequestsNothingAQueuedImportRequestIsDroppedAndALibrarySyncStillMatches(): void
    {
        $this->setAutomaticMatching(false);
        $library = $this->createLibrary();
        $matcher = new class implements AutomaticAlbumMatcherInterface {
            /** @var list<string> */
            public array $matched = [];

            public function match(Uuid $albumId): AutomaticMatchOutcome
            {
                $this->matched[] = $albumId->toString();

                return AutomaticMatchOutcome::matched();
            }
        };
        static::getContainer()->set(AutomaticAlbumMatcherInterface::class, $matcher);

        $this->scanAndIngest($library);
        $album = $this->onlyAlbum($library);
        self::assertNull($this->states()->findByAlbum($album));
        self::assertSame([], $this->queuedRuns());

        // An import queued this request, in another library, while automatic matching was still on.
        $imported = $this->storeAlbum($this->storeLibrary(), 'Austin');
        $this->states()->request($imported, AlbumMatchSource::Ingest, new DateTimeImmutable('-1 minute'));
        static::getContainer()->get(MetadataSyncOrchestrator::class)->syncLibrary($library->getId()->toString());
        $this->handleQueued(SyncLibraryMessage::class, SyncLibraryHandler::class);
        $this->handleQueued(SyncAlbumMessage::class, RequestAlbumMatchHandler::class);
        self::assertSame(AlbumMatchSource::Admin, $this->states()->findByAlbum($album)?->source);

        $drain = static::getContainer()->get(DrainAlbumMatchesHandler::class);
        $drain(new DrainAlbumMatchesMessage());
        $drain(new DrainAlbumMatchesMessage());

        self::assertSame([$album->toString()], $matcher->matched);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM album_match_states'));
    }

    /**
     * Twin editions of "F-1 Trillion: Long Bed" differ only in one track's recording ID. The match
     * takes the earlier edition, credits each duet to Post Malone and its guest, keeps each song's
     * tagged text and lyrics, and the "Post Malone, X" artists are gone.
     */
    public function testAConfidentImportRequestForTwinEditionsMatchesTheAlbumAndMergesTheJointArtists(): void
    {
        $this->setAutomaticMatching(true);
        $musicBrainz = $this->fakeMusicBrainz([$this->longBed(self::EXPLICIT_EDITION, self::YOURS_EXPLICIT), $this->longBed(self::CLEAN_EDITION, self::YOURS_CLEAN)]);
        $fixture = $this->storeLongBed();
        $this->states()->request($fixture['album'], AlbumMatchSource::Ingest, new DateTimeImmutable('-1 minute'));

        static::getContainer()->get(DrainAlbumMatchesHandler::class)(new DrainAlbumMatchesMessage());

        self::assertNotSame([], $musicBrainz->calls);
        self::assertNull($this->states()->findByAlbum($fixture['album']));
        self::assertSame(self::EXPLICIT_EDITION, $this->connection()->fetchOne('SELECT release_mbid FROM albums WHERE id = ?', [$fixture['album']->toString()]));
        self::assertSame(
            [
                ['Wrong Ones', self::WRONG_ONES, 'Post Malone, Tim McGraw', self::POST_MALONE, 'Tim McGraw', 'Lyrics of Wrong Ones'],
                ['Finer Things', self::FINER_THINGS, 'Post Malone, Hank Williams Jr.', self::POST_MALONE, 'Hank Williams Jr.', 'Lyrics of Finer Things'],
                ['Yours', null, 'Post Malone', self::POST_MALONE, null, null],
            ],
            array_map($this->creditsOf(...), $fixture['songs']),
        );
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT count(*) FROM artists WHERE name LIKE 'Post Malone, %'"));
    }

    public function testAnAlbumWhoseBestReleaseHasAnotherTrackCountIsLeftForReviewUnwritten(): void
    {
        $this->setAutomaticMatching(true);
        $this->fakeMusicBrainz([$this->longBed(self::EXPLICIT_EDITION, self::YOURS_EXPLICIT, withBonusTrack: true)]);
        $fixture = $this->storeLongBed();
        $this->states()->request($fixture['album'], AlbumMatchSource::Ingest, new DateTimeImmutable('-1 minute'));

        static::getContainer()->get(DrainAlbumMatchesHandler::class)(new DrainAlbumMatchesMessage());

        $state = $this->states()->findByAlbum($fixture['album']);
        self::assertSame(AlbumMatchStatus::NeedsReview, $state?->status);
        self::assertSame(AlbumMatchReviewReason::TrackCountDiffers, $state->reason);
        self::assertSame(3, $state->checkedSongCount);
        self::assertNull($this->connection()->fetchOne('SELECT release_mbid FROM albums WHERE id = ?', [$fixture['album']->toString()]));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM songs WHERE mbid IS NOT NULL'));
        self::assertSame(2, (int) $this->connection()->fetchOne("SELECT count(*) FROM artists WHERE name LIKE 'Post Malone, %'"));
    }

    public function testATrackAddedAfterAnApplyIsPairedAgainstTheStoredReleaseAndCreditedFromIt(): void
    {
        $this->setAutomaticMatching(true);
        $musicBrainz = $this->fakeMusicBrainz([$this->longBed(self::EXPLICIT_EDITION, self::YOURS_EXPLICIT)]);
        $fixture = $this->storeLongBed(matchedBefore: 1);
        $this->states()->request($fixture['album'], AlbumMatchSource::Ingest, new DateTimeImmutable('-1 minute'));

        static::getContainer()->get(DrainAlbumMatchesHandler::class)(new DrainAlbumMatchesMessage());

        self::assertSame([], array_filter($musicBrainz->calls, static fn (string $call): bool => str_starts_with($call, 'findReleases')), 'A matched album is not searched again.');
        self::assertSame(
            ['Finer Things', self::FINER_THINGS, 'Post Malone, Hank Williams Jr.', self::POST_MALONE, 'Hank Williams Jr.', 'Lyrics of Finer Things'],
            $this->creditsOf($fixture['songs'][1]),
        );
        self::assertSame(self::YOURS_EXPLICIT, $this->connection()->fetchOne('SELECT mbid FROM songs WHERE id = ?', [$fixture['songs'][2]]));
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT count(*) FROM artists WHERE name = 'Post Malone, Hank Williams Jr.'"));
    }

    /**
     * An admin credited the matched album to another artist than its release names. The match of
     * the songs that arrived since credits them from their tracks and leaves the album's own
     * credits, and the artist they name, as the admin left them.
     */
    public function testAMatchOfAMatchedAlbumsNewSongsLeavesTheAlbumCreditsAnAdminSetByHand(): void
    {
        $this->setAutomaticMatching(true);
        $this->fakeMusicBrainz([$this->longBed(self::EXPLICIT_EDITION, self::YOURS_EXPLICIT)]);
        $fixture = $this->storeLongBed(matchedBefore: 1);
        $austinPost = $this->storeArtist('Austin Post');
        $this->connection()->executeStatement(
            "UPDATE artist_album SET artist_id = ?, credited_name = 'Austin Post' WHERE album_id = ?",
            [$austinPost, $fixture['album']->toString()],
        );
        $this->states()->request($fixture['album'], AlbumMatchSource::Ingest, new DateTimeImmutable('-1 minute'));

        static::getContainer()->get(DrainAlbumMatchesHandler::class)(new DrainAlbumMatchesMessage());

        self::assertNull($this->states()->findByAlbum($fixture['album']), 'The run matched the album.');
        self::assertSame(
            ['Finer Things', self::FINER_THINGS, 'Post Malone, Hank Williams Jr.', self::POST_MALONE, 'Hank Williams Jr.', 'Lyrics of Finer Things'],
            $this->creditsOf($fixture['songs'][1]),
        );
        self::assertSame(
            [['Austin Post', 'primary', 'Austin Post']],
            $this->connection()->fetchAllNumeric(
                'SELECT a.name, c.role, c.credited_name FROM artist_album c JOIN artists a ON a.id = c.artist_id WHERE c.album_id = ?',
                [$fixture['album']->toString()],
            ),
        );
    }

    /**
     * Three files of one directory credit Headhunterz by a MusicBrainz ID the catalog does not
     * hold. The import creates the artist with the ID and queues one fill of it once its batch
     * committed; the fill takes the artist's details from MusicBrainz. An admin sync within 30 days
     * queues no fill for the artist, although its life span has no end.
     */
    public function testAnImportThatCreditsAnArtistByANewIdQueuesOneFillAndAnAdminSyncThenAsksNoMore(): void
    {
        $this->setAutomaticMatching(true);
        file_put_contents($this->libraryDirectory . '/Album/track-2.flac', 'not really audio either');
        file_put_contents($this->libraryDirectory . '/Album/track-3.flac', 'not really audio at all');
        $this->tagEveryFile('Headhunterz', self::HEADHUNTERZ);
        $this->fakeMusicBrainz([], [new MusicBrainzArtist(self::HEADHUNTERZ, 'Headhunterz', 100, 'Headhunterz', 'Person', 'NL', 'Dutch hardstyle DJ', '1985-09-12', null, false)]);
        $library = $this->createLibrary();

        $this->scanAndIngest($library);

        $artistId = (string) $this->connection()->fetchOne('SELECT id FROM artists WHERE mbid = ?', [self::HEADHUNTERZ]);
        self::assertSame(3, (int) $this->connection()->fetchOne('SELECT count(*) FROM artist_song WHERE artist_id = ?', [$artistId]));
        self::assertSame([$artistId], $this->queuedArtistSyncs(), 'One fill of the artist, however many songs credit it.');

        $this->handleQueued(SyncArtistMessage::class, SyncArtistHandler::class);

        self::assertSame(
            ['Headhunterz', 'Person', 'NL', 'Dutch hardstyle DJ', '1985-09-12', null],
            $this->connection()->fetchNumeric('SELECT sort_name, type, country, disambiguation, life_span_begin, life_span_end FROM artists WHERE id = ?', [$artistId]),
        );
        self::assertSame(0, static::getContainer()->get(QueueArtistSyncsHandler::class)(new SyncArtistsMessage()), 'MusicBrainz answered about the artist\'s ID within 30 days.');
        self::assertSame([], $this->queuedArtistSyncs());
    }

    /** Gives every file the scan reads the tags of one song in the directory's album, credited to the artist by its MusicBrainz ID. */
    private function tagEveryFile(string $artist, string $musicbrainzId): void
    {
        $reader = $this->createStub(MetadataContentReaderPortInterface::class);
        $reader->method('readMetadata')->willReturnCallback(static fn (string $path): ExtractedMetadata => new ExtractedMetadata()
            ->setTitle(pathinfo($path, PATHINFO_FILENAME))
            ->setAlbum('Album')
            ->setArtists([$artist])
            ->setMbArtistIds([$musicbrainzId]));
        static::getContainer()->set(MetadataContentReaderPortInterface::class, $reader);
    }

    /** @return list<string> the artist of each artist fill waiting on the async transport */
    private function queuedArtistSyncs(): array
    {
        return array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->queued())
            |> (static fn (array $messages): array => array_filter($messages, static fn (object $message): bool => $message instanceof SyncArtistMessage))
            |> (static fn (array $syncs): array => array_map(static fn (SyncArtistMessage $sync): string => $sync->artistId->toString(), $syncs))
            |> array_values(...);
    }

    private function setAutomaticMatching(bool $enabled): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->save([MetadataSettingDefinitions::AUTO_SYNC => $enabled]);
    }

    private function states(): AlbumMatchStateRepositoryInterface
    {
        return static::getContainer()->get(AlbumMatchStateRepositoryInterface::class);
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function createLibrary(): Library
    {
        $library = Library::create(
            'Album sync',
            new LibrarySlug('album-sync-' . (random_bytes(4) |> bin2hex(...))),
            new LibraryPath($this->libraryDirectory),
            LibraryType::Music,
            FilesystemType::Local,
        );
        static::getContainer()->get(LibraryPortInterface::class)->save($library);

        return $library;
    }

    /**
     * Scans through the console, then hands each new discovery to the ingest handler.
     *
     * @return list<FilesDiscovered>
     */
    private function scanAndIngest(Library $library): array
    {
        $tester = new CommandTester(new Application(self::$kernel)->find('app:library:scan'));
        $exitCode = $tester->execute(['library' => $library->getSlug()->toString()]);
        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        static::getContainer()->get(RelayOutboxHandler::class)(new RelayOutboxCommand());
        $ingest = static::getContainer()->get(FilesDiscoveredHandler::class);
        $discoveries = [];
        foreach ($this->queued() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FilesDiscovered && $message->libraryId->equals($library->getId())) {
                $ingest($message);
                $discoveries[] = $message;
                $this->async()->ack($envelope);
            }
        }

        return $discoveries;
    }

    /**
     * Hands each queued message of the class to its handler, as a worker would.
     *
     * @param class-string $messageClass
     * @param class-string $handlerClass
     */
    private function handleQueued(string $messageClass, string $handlerClass): void
    {
        $handler = static::getContainer()->get($handlerClass);
        foreach ($this->queued() as $envelope) {
            if ($envelope->getMessage() instanceof $messageClass) {
                $handler($envelope->getMessage());
                $this->async()->ack($envelope);
            }
        }
    }

    /**
     * Every message waiting on the async transport, oldest first. A fetch from the transport hands
     * out one message only, as a worker takes them, so the waiting ones are those sent and neither
     * acknowledged nor rejected yet.
     *
     * @return list<Envelope>
     */
    private function queued(): array
    {
        $async = $this->async();
        $done = array_map(self::transportId(...), [...$async->getAcknowledged(), ...$async->getRejected()]);

        return array_filter($async->getSent(), static fn (Envelope $envelope): bool => !in_array(self::transportId($envelope), $done, true))
            |> array_values(...);
    }

    private static function transportId(Envelope $envelope): mixed
    {
        return $envelope->last(TransportMessageIdStamp::class)?->getId();
    }

    private function onlyAlbum(Library $library): Uuid
    {
        $albums = static::getContainer()->get(AlbumPortInterface::class)->findByLibrary($library->getId());
        self::assertCount(1, $albums);

        return $albums[0]->getId();
    }

    /** @return list<int|null> the delay of each queued album match queue run, in milliseconds */
    private function queuedRuns(): array
    {
        $runs = [];
        foreach ($this->async()->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof DrainAlbumMatchesMessage) {
                $runs[] = $envelope->last(DelayStamp::class)?->getDelay();
            }
        }

        return $runs;
    }

    private function async(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * "F-1 Trillion: Long Bed" as the scan imports it, cut down to three tracks on two discs: two
     * duets credited to "Post Malone, <guest>" artists of their own, with lyrics, and one solo song.
     * With $matchedBefore, an earlier apply matched the album after its first $matchedBefore songs
     * were created and before the others were.
     *
     * @return array{album: Uuid, songs: list<string>}
     */
    private function storeLongBed(int $matchedBefore = 0): array
    {
        $libraryId = $this->storeLibrary();
        $album = $this->storeAlbum($libraryId, 'F-1 Trillion: Long Bed');
        $postMalone = $this->storeArtist('Post Malone', $matchedBefore > 0 ? self::POST_MALONE : null);
        $this->connection()->executeStatement(
            "INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, 'primary', 'Post Malone')",
            [new Uuid()->toString(), $postMalone, $album->toString()],
        );
        $tracks = [
            ['Wrong Ones', 1, 1, 236.0, 'Post Malone, Tim McGraw', 'Lyrics of Wrong Ones'],
            ['Finer Things', 1, 2, 218.0, 'Post Malone, Hank Williams Jr.', 'Lyrics of Finer Things'],
            ['Yours', 2, 1, 199.0, 'Post Malone', null],
        ];
        $songs = [];
        foreach ($tracks as $index => [$title, $disc, $track, $length, $credit, $lyrics]) {
            $artist = $credit === 'Post Malone' ? $postMalone : $this->storeArtist($credit);
            $createdAt = $index < $matchedBefore || $matchedBefore === 0 ? '-2 hours' : '-10 minutes';
            $songs[] = $id = new Uuid()->toString();
            $this->connection()->executeStatement(
                'INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, length, disc, track, lyrics, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1000000, ?, ?, ?, ?, ?, ?, clock_timestamp())',
                [$id, new PublicId()->toString(), $album->toString(), $title, '/music/F-1 Trillion/' . $disc . '-' . $track . '.flac', 'audio/flac', $length, $disc, $track, $lyrics, new DateTimeImmutable($createdAt)->format('Y-m-d H:i:s.uP')],
            );
            $this->connection()->executeStatement(
                "INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (?, ?, ?, 'primary', ?)",
                [new Uuid()->toString(), $artist, $id, $credit],
            );
        }
        if ($matchedBefore > 0) {
            $this->connection()->executeStatement(
                "UPDATE albums SET release_mbid = ?, mbid = ?, matched_at = clock_timestamp() - interval '1 hour' WHERE id = ?",
                [self::EXPLICIT_EDITION, self::RELEASE_GROUP, $album->toString()],
            );
            $this->connection()->executeStatement('UPDATE songs SET mbid = ? WHERE id = ?', [self::WRONG_ONES, $songs[0]]);
        }

        return ['album' => $album, 'songs' => $songs];
    }

    private function storeLibrary(): Uuid
    {
        $id = new Uuid();
        $this->connection()->executeStatement(
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at) VALUES (?, ?, 'Country', ?, ?, 'music', clock_timestamp(), clock_timestamp())",
            [$id->toString(), new PublicId()->toString(), 'country-' . (random_bytes(4) |> bin2hex(...)), '/country-' . (random_bytes(4) |> bin2hex(...))],
        );

        return $id;
    }

    private function storeAlbum(Uuid $libraryId, string $title): Uuid
    {
        $id = new Uuid();
        $this->connection()->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (?, ?, ?, ?, 'album', clock_timestamp(), clock_timestamp())",
            [$id->toString(), new PublicId()->toString(), $libraryId->toString(), $title],
        );

        return $id;
    }

    private function storeArtist(string $name, ?string $mbid = null): string
    {
        $id = new Uuid()->toString();
        $this->connection()->executeStatement(
            'INSERT INTO artists (id, public_id, name, mbid, created_at, updated_at) VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$id, new PublicId()->toString(), $name, $mbid],
        );

        return $id;
    }

    /** @return list<string|null> title, recording ID, the primary credit's text, the primary and featured artists' IDs and names, lyrics */
    private function creditsOf(string $songId): array
    {
        $song = $this->connection()->fetchAssociative('SELECT title, mbid, lyrics FROM songs WHERE id = ?', [$songId]);
        self::assertIsArray($song);
        $primary = $this->connection()->fetchAssociative(
            "SELECT s.credited_name, a.mbid FROM artist_song s JOIN artists a ON a.id = s.artist_id WHERE s.song_id = ? AND s.role = 'primary'",
            [$songId],
        );
        self::assertIsArray($primary);
        $featured = $this->connection()->fetchOne(
            "SELECT a.name FROM artist_song s JOIN artists a ON a.id = s.artist_id WHERE s.song_id = ? AND s.role = 'featured'",
            [$songId],
        );

        return [$song['title'], $song['mbid'], $primary['credited_name'], $primary['mbid'], $featured === false ? null : $featured, $song['lyrics']];
    }

    /**
     * An edition of "F-1 Trillion: Long Bed" as MusicBrainz describes it. Every edition shares the
     * duets' recordings; each has its own recording of "Yours".
     */
    private function longBed(string $releaseId, string $yoursRecording, bool $withBonusTrack = false): MusicBrainzRelease
    {
        $postMalone = new MusicBrainzCreditedArtist(self::POST_MALONE, 'Post Malone', '', 'Post Malone', 'Malone, Post');
        $duet = static fn (string $guestId, string $guest): MusicBrainzArtistCredit => new MusicBrainzArtistCredit([
            new MusicBrainzCreditedArtist(self::POST_MALONE, 'Post Malone', ' & ', 'Post Malone', 'Malone, Post'),
            new MusicBrainzCreditedArtist($guestId, $guest, '', $guest, null),
        ]);
        $solo = new MusicBrainzArtistCredit([$postMalone]);
        $discTwo = [new MusicBrainzTrack(self::trackId($releaseId, 3), 1, '1', 'Yours', 199_500, $yoursRecording, $solo)];
        if ($withBonusTrack) {
            $discTwo[] = new MusicBrainzTrack(self::trackId($releaseId, 4), 2, '2', 'Never Love You Again', 201_000, self::NEVER_LOVE_YOU_AGAIN, $solo);
        }

        return new MusicBrainzRelease(
            id: $releaseId,
            title: 'F-1 Trillion: Long Bed',
            artistCredit: $solo,
            date: '2024-08-16',
            country: 'XW',
            status: 'Official',
            disambiguation: null,
            barcode: null,
            labels: [new MusicBrainzLabel(null, 'Mercury Records', null)],
            media: [
                new MusicBrainzMedium(1, 'Digital Media', null, [
                    new MusicBrainzTrack(self::trackId($releaseId, 1), 1, '1', 'Wrong Ones', 236_400, self::WRONG_ONES, $duet(self::TIM_MCGRAW, 'Tim McGraw')),
                    new MusicBrainzTrack(self::trackId($releaseId, 2), 2, '2', 'Finer Things', 218_200, self::FINER_THINGS, $duet(self::HANK_WILLIAMS_JR, 'Hank Williams Jr.')),
                ]),
                new MusicBrainzMedium(2, 'Digital Media', null, $discTwo),
            ],
            releaseGroup: new MusicBrainzReleaseGroup(self::RELEASE_GROUP, 'F-1 Trillion', 'Album', '2024-08-16', ['country']),
            genres: [],
            hasFrontCover: false,
        );
    }

    /** A track ID of its own for each release and position. */
    private static function trackId(string $releaseId, int $number): string
    {
        return substr($releaseId, 0, -2) . sprintf('%02d', 10 + $number);
    }

    /**
     * Puts a fake MusicBrainz in the container that finds the given releases for any search and
     * answers a lookup of each given artist by its ID.
     *
     * @param list<MusicBrainzRelease> $releases
     * @param list<MusicBrainzArtist>  $artists
     *
     * @return object{calls: list<string>}
     */
    private function fakeMusicBrainz(array $releases, array $artists = []): object
    {
        $fake = new class ($releases, $artists) implements MusicBrainzLookupInterface {
            /** @var list<string> */
            public array $calls = [];

            /**
             * @param list<MusicBrainzRelease> $releases
             * @param list<MusicBrainzArtist>  $artists
             */
            public function __construct(private readonly array $releases, private readonly array $artists)
            {
            }

            /** @phpstan-pure */
            public function isEnabled(): bool
            {
                return true;
            }

            public function findReleases(string $title, ?string $artist, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array
            {
                $this->calls[] = 'findReleases:' . $title . '|' . $artist;

                return array_map(static fn (MusicBrainzRelease $release): MusicBrainzReleaseSummary => new MusicBrainzReleaseSummary(
                    $release->id, $release->title, 100, $release->artistCredit, $release->date, $release->country, $release->status,
                    $release->disambiguation, $release->barcode, $release->labels,
                    array_map(static fn (MusicBrainzMedium $medium): MusicBrainzMediumSummary => new MusicBrainzMediumSummary($medium->format, count($medium->tracks)), $release->media),
                    $release->trackCount(), $release->releaseGroup,
                ), $this->releases);
            }

            public function fetchRelease(string $releaseId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): ?MusicBrainzRelease
            {
                $this->calls[] = 'fetchRelease:' . $releaseId;

                return array_find($this->releases, static fn (MusicBrainzRelease $release): bool => $release->id === $releaseId);
            }

            public function findRecordings(string $title, ?string $artist, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array
            {
                $this->calls[] = 'findRecordings:' . $title;

                return [];
            }

            public function fetchRecording(string $recordingId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzRecording|MusicBrainzRejected|MusicBrainzUnavailable|null
            {
                $this->calls[] = 'fetchRecording:' . $recordingId;

                return null;
            }

            public function findArtists(string $name, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): array
            {
                $this->calls[] = 'findArtists:' . $name;

                return [];
            }

            public function fetchArtist(string $artistId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): ?MusicBrainzArtist
            {
                $this->calls[] = 'fetchArtist:' . $artistId;

                return array_find($this->artists, static fn (MusicBrainzArtist $artist): bool => $artist->id === $artistId);
            }

            public function fetchFrontCover(string $releaseId, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): MusicBrainzCoverImage|MusicBrainzRejected|MusicBrainzUnavailable|null
            {
                $this->calls[] = 'fetchFrontCover:' . $releaseId;

                return null;
            }
        };
        static::getContainer()->set(MusicBrainzLookupInterface::class, $fake);

        return $fake;
    }

    private function isolateSchema(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $this->searchPath = (string) $connection->fetchOne('SHOW search_path');
        $this->schema = 'scan_test_' . (random_bytes(8) |> bin2hex(...));
        $schema = $connection->quoteIdentifier($this->schema);
        $connection->executeStatement('CREATE SCHEMA ' . $schema);
        $connection->executeStatement('SET search_path TO ' . $schema . ', public');
        FinalSchemaBootstrap::bootstrap($connection);
    }
}
